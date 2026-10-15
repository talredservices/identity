<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\Infrastructure\Services\Identity;

use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityAccountSecurity;
use App\Services\UserManagementService\Application\Contracts\Identity\Authentication\ManageIdentityGoogleAuthorization;
use App\Services\UserManagementService\Application\Contracts\OAuthGateway;
use App\Services\UserManagementService\Application\Exceptions\IdentityAuthenticationException;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityAuthorizationCode;
use App\Services\UserManagementService\Infrastructure\Models\Eloquent\IdentityHostedApplication;
use App\Services\UserManagementService\Infrastructure\Services\EloquentIdentityAuthenticationService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class IdentityGoogleAuthorization implements ManageIdentityGoogleAuthorization
{
    public function __construct(private IdentityClientAuthenticator $clients, private EloquentIdentityAuthenticationService $authentication, private ManageIdentityAccountSecurity $security, private OAuthGateway $oauth) {}

    public function start(array $input): array
    {
        $client = $this->clients->authenticate($input['client_id'], $input['client_secret']);
        $application = IdentityHostedApplication::query()->where('key', $input['application_key'])
            ->where('primary_client_id', $client->id)->where('status', 'active')->where('authentication_mode', 'app')->first();
        if (! $application || ! ($application->authentication['google_enabled'] ?? false)
            || ! $client->project->google_social_authentication_enabled
            || $application->callback_url !== $input['redirect_uri']) {
            throw new IdentityAuthenticationException('Google authentication is unavailable for this application.');
        }
        $sourceToken = null;
        if (! empty($input['sensitive_operation'])) {
            $sourceToken = PersonalAccessToken::findToken((string) ($input['source_access_token'] ?? ''));
            if (! $sourceToken || (string) $sourceToken->identity_client_id !== (string) $client->id || $sourceToken->expires_at?->isPast()) {
                throw new IdentityAuthenticationException('An active session for this client is required.');
            }
        }
        $termsRequired = ($application->authentication['terms_required'] ?? false) === true;
        if ($sourceToken === null && $termsRequired && (($input['terms_accepted'] ?? false) !== true || ! $application->policy_revision
            || ($input['policy_revision'] ?? null) !== $application->policy_revision)) {
            throw new IdentityAuthenticationException('Acceptance of the current application terms is required.');
        }
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            throw new IdentityAuthenticationException('Google authentication is not configured.');
        }
        $state = Str::random(64);
        DB::table('identity_provider_transactions')->insert([
            'state_hash' => hash('sha256', $state), 'application_id' => $application->id,
            'client_id' => $client->id, 'client_secret_cipher' => Crypt::encryptString($input['client_secret']),
            'source_token_cipher' => $sourceToken ? Crypt::encryptString($input['source_access_token']) : null,
            'sensitive_operation' => $input['sensitive_operation'] ?? null,
            'consumer_state' => $input['state'], 'callback_url' => $application->callback_url,
            'terms_accepted' => $termsRequired, 'policy_revision' => $application->policy_revision,
            'expires_at' => now()->addMinutes(10), 'created_at' => now(),
        ]);

        return ['redirect_url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'), 'redirect_uri' => config('services.google.identity_redirect'),
            'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state,
            'prompt' => 'select_account',
        ])];
    }

    public function complete(array $input): array
    {
        // Commit consumption before contacting the provider: failed exchanges cannot be replayed.
        $transaction = DB::transaction(function () use ($input): object {
            $transaction = DB::table('identity_provider_transactions')->where('state_hash', hash('sha256', $input['state']))->lockForUpdate()->first();
            if (! $transaction || $transaction->consumed_at !== null || now()->greaterThanOrEqualTo($transaction->expires_at)) {
                throw new IdentityAuthenticationException('The Google transaction is invalid or expired.');
            }
            DB::table('identity_provider_transactions')->where('state_hash', $transaction->state_hash)
                ->update(['consumed_at' => now(), 'client_secret_cipher' => '', 'source_token_cipher' => null]);

            return $transaction;
        });
        $application = IdentityHostedApplication::query()->with('primaryClient.project')->find($transaction->application_id);
        if (! $application || $application->status !== 'active' || $application->authentication_mode !== 'app'
            || $application->callback_url !== $transaction->callback_url
            || $application->primary_client_id !== $transaction->client_id
            || ! ($application->authentication['google_enabled'] ?? false)
            || $application->primaryClient?->status !== 'active'
            || $application->primaryClient?->project?->status !== 'active'
            || ! $application->primaryClient?->project?->google_social_authentication_enabled
            || (($application->authentication['terms_required'] ?? false) && ! $transaction->terms_accepted && $transaction->sensitive_operation === null)
            || $application->policy_revision !== $transaction->policy_revision) {
            throw new IdentityAuthenticationException('The application integration changed during authentication.');
        }
        $callback = $transaction->callback_url;
        if (! empty($input['error'])) {
            return ['redirect_url' => $callback.'?'.http_build_query(['state' => $transaction->consumer_state, 'error' => 'access_denied'])];
        }
        $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.identity_redirect'), 'grant_type' => 'authorization_code', 'code' => $input['code'],
        ]);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new IdentityAuthenticationException('The Google authorization code could not be exchanged.');
        }
        if ($transaction->sensitive_operation !== null) {
            $sourceToken = Crypt::decryptString($transaction->source_token_cipher);
            $remote = $this->oauth->fetchUser('google', $response->json('access_token'));
            $grant = $this->security->reauthenticateWithGoogle($sourceToken, $transaction->client_id, $remote->providerUserId, $transaction->sensitive_operation);
            $token = PersonalAccessToken::findToken($sourceToken);
            $code = Str::random(96);
            IdentityAuthorizationCode::query()->create([
                'user_id' => $token->tokenable_id, 'project_id' => $token->identity_project_id,
                'client_id' => $transaction->client_id, 'code_hash' => hash('sha256', $code),
                'redirect_uri' => $callback, 'expires_at' => now()->addMinutes(2),
                'reauthentication_cipher' => Crypt::encryptString(json_encode($grant + ['source_token_hash' => hash('sha256', $sourceToken)], JSON_THROW_ON_ERROR)),
            ]);

            return ['redirect_url' => $callback.'?'.http_build_query(['state' => $transaction->consumer_state, 'code' => $code])];
        }
        $credentials = ['client_id' => $transaction->client_id, 'client_secret' => Crypt::decryptString($transaction->client_secret_cipher)];
        $login = $this->authentication->socialLogin($credentials + [
            'provider' => 'google', 'access_token' => $response->json('access_token'),
            'hosted_application_id' => $application->id, 'terms_required' => (bool) $transaction->terms_accepted,
            'terms_url' => $application->authentication['terms_url'] ?? null, 'policy_revision' => $transaction->policy_revision,
        ]);
        $handoff = $this->authentication->createHandoff($login['identity']['user']['id'], $login['access_token'], $credentials + ['redirect_uri' => $callback]);

        return ['redirect_url' => $callback.'?'.http_build_query(['state' => $transaction->consumer_state, 'code' => $handoff['code']])];
    }
}

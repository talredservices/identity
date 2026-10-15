<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Requests\Identity;

use App\Services\UserManagementService\Domain\Enums\IdentitySensitiveOperation;
use Illuminate\Validation\Rule;

final class IdentityAuthenticationOperationRequest extends IdentityOperationRequest
{
    private const AUTHENTICATED_OPERATIONS = [
        'auth.reauthenticate',
        'auth.account.password',
        'auth.me',
        'auth.sessions.index',
        'auth.sessions.revoke',
        'auth.logout',
        'auth.verification.resend',
        'auth.verification.verify',
        'auth.handoff.create',
        'auth.account.intent.create',
    ];

    public function authorize(): bool
    {
        return ! in_array($this->operation(), self::AUTHENTICATED_OPERATIONS, true)
            || $this->hasAuthenticatedIdentity();
    }

    /** @return array<string, mixed> */
    public function routeParams(): array
    {
        return $this->operation() === 'auth.sessions.revoke'
            ? ['session' => ['type' => 'string', 'required' => true]]
            : [];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->operation()) {
            'auth.reauthenticate' => $this->clientRules([
                'password' => ['required', 'string'],
                'sensitive_operation' => ['required', Rule::enum(IdentitySensitiveOperation::class)],
            ]),
            'auth.account.password' => ['password' => ['required', 'string', 'min:12', 'confirmed']],
            'auth.google.start' => $this->clientRules([
                'sensitive_operation' => ['nullable', Rule::enum(IdentitySensitiveOperation::class)],
                'application_key' => ['required', 'string', 'max:100'],
                'redirect_uri' => ['required', 'url:http,https', 'max:2048'],
                'state' => ['required', 'string', 'min:32', 'max:180', 'regex:/^[A-Za-z0-9_-]+$/'],
                'terms_accepted' => ['sometimes', 'boolean'],
                'policy_revision' => ['nullable', 'string', 'max:128'],
            ]),
            'auth.google.callback' => [
                'state' => ['required', 'string', 'size:64'],
                'code' => ['required_without:error', 'string', 'max:4096'],
                'error' => ['sometimes', 'string', 'max:255'],
            ],
            'auth.context' => $this->clientRules(['project' => ['nullable', 'string', 'max:255']]),
            'auth.login' => $this->clientRules([
                'project' => ['nullable', 'string', 'max:255'],
                'email' => ['required', 'email'],
                'password' => ['required', 'string'],
            ]),
            'auth.register' => $this->clientRules([
                'project' => ['nullable', 'string', 'max:255'],
                'application_key' => ['sometimes', 'string', 'max:100'],
                'terms_accepted' => ['sometimes', 'boolean'],
                'policy_revision' => ['nullable', 'string', 'max:128'],
                'username' => ['required', 'string', 'min:2', 'max:100'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:12', 'confirmed'],
            ]),
            'auth.sandbox-session' => $this->clientRules(),
            'auth.refresh' => $this->clientRules(['refresh_token' => ['required', 'string', 'min:64']]),
            'auth.password.forgot' => $this->clientRules(['email' => ['required', 'email']]),
            'auth.password.reset' => $this->clientRules([
                'email' => ['required', 'email'],
                'token' => ['required', 'string', 'min:64'],
                'password' => ['required', 'string', 'min:12', 'confirmed'],
            ]),
            'auth.introspect' => $this->clientRules(['token' => ['required', 'string']]),
            'auth.handoff.create' => $this->clientRules([
                'redirect_uri' => ['required', 'url:http,https', 'max:2048'],
            ]),
            'auth.authorization.intent' => $this->clientRules([
                'hosted_application' => ['required', 'string', 'max:255'],
                'state' => ['required', 'string', 'min:32', 'max:180', 'regex:/^[A-Za-z0-9_-]+$/'],
                'demo_account_enabled' => ['required', 'boolean'],
            ]),
            'auth.account.intent.create' => $this->clientRules([
                'hosted_application' => ['required', 'string', 'max:255'],
            ]),
            'auth.logout.intent' => $this->clientRules([
                'hosted_application' => ['required', 'string', 'max:255'],
                'return_to' => ['required', 'string', 'max:2048', 'regex:/^\/(?!\/)(?!.*\\\\).*$/'],
            ]),
            'auth.handoff.exchange' => $this->clientRules([
                'code' => ['required', 'string', 'min:64'],
                'redirect_uri' => ['required', 'url:http,https', 'max:2048'],
            ]),
            'auth.manifest.sync' => $this->clientRules($this->manifestRules()),
            'auth.invitation.accept' => [
                'invitation_token' => ['required', 'string', 'min:64'],
                'username' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12'],
                'password_confirmation' => ['required', 'same:password'],
            ],
            'auth.verification.verify' => ['code' => ['required', 'digits:6']],
            'auth.sessions.revoke' => ['session' => ['required', 'string', 'max:255']],
            default => [],
        };
    }

    /** @param array<string, mixed> $rules @return array<string, mixed> */
    private function clientRules(array $rules = []): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'client_secret' => ['required', 'string', 'min:32'],
            ...$rules,
        ];
    }

    /** @return array<string, mixed> */
    private function manifestRules(): array
    {
        return [
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*.key' => ['required', 'string', 'regex:/^[a-z0-9]+(?:[._:-][a-z0-9]+)*$/', 'max:160', 'distinct'],
            'permissions.*.name' => ['nullable', 'string', 'max:255'],
            'permissions.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

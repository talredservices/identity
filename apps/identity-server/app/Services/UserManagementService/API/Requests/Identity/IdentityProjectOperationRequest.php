<?php

declare(strict_types=1);

namespace App\Services\UserManagementService\API\Requests\Identity;

use Illuminate\Validation\Rule;

final class IdentityProjectOperationRequest extends IdentityOperationRequest
{
    protected function operation(): string
    {
        return str_replace('projects.applications.', 'projects.hosted_applications.', parent::operation());
    }

    public function trustedData(): array
    {
        $data = parent::trustedData();
        $routeName = (string) $this->route()?->getName();
        if (str_starts_with($routeName, 'identity.projects.applications.')) {
            $data['input']['authentication_mode'] = 'hosted';
            $data['input']['password_reset_url'] = null;
        }

        return $data;
    }

    public function authorize(): bool
    {
        return $this->hasAuthenticatedIdentity();
    }

    /** @return array<string, mixed> */
    public function routeParams(): array
    {
        $params = [];
        foreach (['project', 'client', 'webhook', 'role', 'permission', 'membership', 'hosted_application'] as $parameter) {
            if ($this->route($parameter) !== null) {
                $params[$parameter] = ['type' => 'string', 'required' => true];
            }
        }

        return $params;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $routeRules = array_fill_keys(array_keys($this->routeParams()), ['required', 'uuid']);
        $bodyRules = match ($this->operation()) {
            'projects.store' => [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('identity_projects', 'slug')],
                'description' => ['nullable', 'string', 'max:2000'],
            ],
            'projects.destroy' => ['confirmation' => ['required', 'string', 'max:100']],
            'projects.deletion.cancel' => [],
            'projects.suspension.store' => ['confirmation' => ['required', 'string', 'max:100']],
            'projects.reactivation.store' => [],
            'project_access_catalog.permissions.store' => [
                'key' => ['required', 'string', 'regex:/^[a-z0-9]+(?:[._:-][a-z0-9]+)*$/', 'max:160', Rule::unique('identity_access_catalog_permissions', 'key')],
                'name' => ['nullable', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            ],
            'project_access_catalog.roles.store' => [
                'name' => ['required', 'string', 'max:255'], 'slug' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('identity_access_catalog_roles', 'slug')],
                'description' => ['nullable', 'string', 'max:2000'], 'permission_ids' => ['present', 'array'], 'permission_ids.*' => ['uuid', 'distinct'],
            ],
            'projects.access_catalog.import' => ['permission_ids' => ['present', 'array'], 'permission_ids.*' => ['uuid', 'distinct'], 'role_ids' => ['present', 'array'], 'role_ids.*' => ['uuid', 'distinct']],
            'projects.permissions.publish' => [],
            'projects.roles.publish' => [],
            'projects.registration.update' => [
                'registration_mode' => ['required', Rule::in(['invite_only', 'public'])],
                'registration_role_id' => ['nullable', 'uuid'],
                'email_verification_required' => ['required', 'boolean'],
            ],
            'projects.environment.update' => [
                'mode' => ['required', Rule::in(['live', 'sandbox'])],
                'sandbox_ttl_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            ],
            'projects.webhooks.store' => $this->webhookRules(),
            'projects.webhooks.update' => [
                ...$this->webhookRules(),
                'status' => ['required', Rule::in(['active', 'disabled'])],
            ],
            'projects.clients.store' => ['name' => ['required', 'string', 'max:255']],
            'projects.clients.name' => ['name' => ['required', 'string', 'max:255']],
            'projects.clients.status' => ['status' => ['required', Rule::in(['active', 'disabled'])]],
            'projects.clients.destroy' => ['confirmation' => ['required', 'string', 'max:255']],
            'projects.clients.manifest' => $this->manifestRules(),
            'projects.hosted_applications.store' => [
                'name' => ['required', 'string', 'max:200'],
                'key' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('identity_hosted_applications', 'key')],
                'primary_client_id' => ['required', 'uuid'],
                'sandbox_client_id' => ['nullable', 'uuid'],
                'application_url' => ['required', 'url:http,https', 'max:2048'],
                'callback_url' => ['required', 'url:http,https', 'max:2048'],
                'authentication_mode' => ['sometimes', Rule::in(['hosted', 'app'])],
                'password_reset_url' => ['nullable', 'url:http,https', 'max:2048'],
                'policy_revision' => ['nullable', 'string', 'max:128'],
                'auth_page_set' => ['sometimes', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:100'],
                ...$this->hostedApplicationAppearanceRules(),
                ...$this->hostedApplicationAuthenticationRules(),
            ],
            'projects.hosted_applications.update' => [
                'name' => ['required', 'string', 'max:200'],
                'primary_client_id' => ['required', 'uuid'],
                'sandbox_client_id' => ['nullable', 'uuid'],
                'application_url' => ['required', 'url:http,https', 'max:2048'],
                'callback_url' => ['required', 'url:http,https', 'max:2048'],
                'authentication_mode' => ['sometimes', Rule::in(['hosted', 'app'])],
                'password_reset_url' => ['nullable', 'url:http,https', 'max:2048'],
                'policy_revision' => ['nullable', 'string', 'max:128'],
                'auth_page_set' => ['sometimes', 'string', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:100'],
                'status' => ['required', Rule::in(['active', 'disabled'])],
                ...$this->hostedApplicationAppearanceRules(),
                ...$this->hostedApplicationAuthenticationRules(),
            ],
            'projects.roles.store' => [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('identity_project_roles', 'slug')->where('project_id', (string) $this->route('project'))],
                'description' => ['nullable', 'string', 'max:2000'],
            ],
            'projects.roles.destroy' => [
                'confirmation' => ['required', 'string', 'max:100'],
            ],
            'projects.permissions.store' => [
                'key' => ['required', 'string', 'regex:/^[a-z0-9]+(?:[._:-][a-z0-9]+)*$/', 'max:160', Rule::unique('identity_project_permissions', 'key')->where('project_id', (string) $this->route('project'))],
                'name' => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
            ],
            'projects.permissions.destroy' => [
                'confirmation' => ['required', 'string', 'max:160'],
            ],
            'projects.roles.permissions' => [
                'permission_ids' => ['present', 'array'],
                'permission_ids.*' => ['uuid', 'distinct'],
            ],
            'projects.invitations.store' => [
                'hosted_application_id' => ['required', 'uuid'],
                'email' => ['required', 'email'],
                'is_admin' => ['sometimes', 'boolean'],
            ],
            'projects.memberships.access' => [
                'role_ids' => ['present', 'array'],
                'role_ids.*' => ['uuid', 'distinct'],
                'permission_ids' => ['present', 'array'],
                'permission_ids.*' => ['uuid', 'distinct'],
                'is_admin' => ['required', 'boolean'],
                'status' => ['required', Rule::in(['active', 'suspended'])],
            ],
            'projects.audit' => ['limit' => ['sometimes', 'integer', 'min:1', 'max:250']],
            default => [],
        };

        return [...$routeRules, ...$bodyRules];
    }

    /** @return array<string, mixed> */
    private function webhookRules(): array
    {
        return [
            'url' => ['required', 'url:http,https', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', Rule::in(['identity.user.expired', 'identity.user.deletion_requested'])],
        ];
    }

    /** @return array<string, mixed> */
    private function hostedApplicationAppearanceRules(): array
    {
        return [
            'appearance' => ['sometimes', 'array'],
            'appearance.welcome_text' => ['nullable', 'string', 'max:280'],
            'appearance.accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'appearance.background_preset' => ['nullable', Rule::in(['identity', 'slate', 'indigo', 'emerald', 'sunset'])],
            'appearance.design_tokens' => ['sometimes', 'array'],
            'appearance.design_tokens.*' => ['string', 'max:200'],
        ];
    }

    /** @return array<string, mixed> */
    private function hostedApplicationAuthenticationRules(): array
    {
        return [
            'authentication' => ['sometimes', 'array'],
            'authentication.google_enabled' => ['nullable', 'boolean'],
            'authentication.terms_required' => ['nullable', 'boolean'],
            'authentication.terms_url' => ['nullable', 'url:http,https', 'max:2048'],
            'authentication.privacy_url' => ['nullable', 'url:http,https', 'max:2048'],
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

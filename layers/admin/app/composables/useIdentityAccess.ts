import type {
  IdentityBrowserSession,
  IdentityAccountSession,
  IdentityAuditEvent,
  IdentityAccessCatalog,
  IdentityAccessCatalogPermission,
  IdentityAccessCatalogRole,
  IdentityClient,
  IdentityHostedApplication,
  IdentityInstallationUser,
  IdentityProject,
  IdentityProjectDetails,
  IdentityRole,
  IdentityWebhook
} from '../../types/identity-access'

export function useIdentityAccess() {
  const authenticatedFetch = useAuthenticatedFetch()
  const { csrf, headerName } = useCsrf()
  const mutation = <T>(url: string, options: Parameters<typeof authenticatedFetch<T>>[1]) =>
    authenticatedFetch<T>(url, {
      ...options,
      headers: { [headerName]: csrf, ...options?.headers }
    })

  return {
    session: () => useFetch<IdentityBrowserSession>('/api/auth/session'),
    projects: () => useFetch<IdentityProject[]>('/api/identity/projects'),
    users: () => useFetch<IdentityInstallationUser[]>('/api/identity/users'),
    accountSessions: () => useFetch<IdentityAccountSession[]>('/api/auth/sessions'),
    project: (id: MaybeRefOrGetter<string>) => useFetch<IdentityProjectDetails>(() => `/api/identity/projects/${toValue(id)}`),
    projectAccessCatalog: () => useFetch<IdentityAccessCatalog>('/api/identity/project-access-catalog'),
    audit: (id: MaybeRefOrGetter<string>, options: { immediate?: boolean } = {}) => useFetch<IdentityAuditEvent[]>(
      () => `/api/identity/projects/${toValue(id)}/audit`,
      { immediate: options.immediate ?? true }
    ),
    createProject: (body: { name: string, slug: string, description?: string | null }) =>
      mutation<IdentityProject>('/api/identity/projects', { method: 'POST', body }),
    scheduleProjectDeletion: (projectId: string, confirmation: string) =>
      mutation<IdentityProject>(`/api/identity/projects/${projectId}`, { method: 'DELETE', body: { confirmation } }),
    cancelProjectDeletion: (projectId: string) =>
      mutation<IdentityProject>(`/api/identity/projects/${projectId}/deletion/cancel`, { method: 'POST' }),
    suspendProject: (projectId: string, confirmation: string) =>
      mutation<IdentityProject>(`/api/identity/projects/${projectId}/suspension`, { method: 'POST', body: { confirmation } }),
    reactivateProject: (projectId: string) =>
      mutation<IdentityProject>(`/api/identity/projects/${projectId}/reactivation`, { method: 'POST' }),
    createCatalogPermission: (body: { key: string, name?: string | null, description?: string | null }) =>
      mutation<IdentityAccessCatalogPermission>('/api/identity/project-access-catalog/permissions', { method: 'POST', body }),
    createCatalogRole: (body: { name: string, slug: string, description?: string | null, permission_ids: string[] }) =>
      mutation<IdentityAccessCatalogRole>('/api/identity/project-access-catalog/roles', { method: 'POST', body }),
    importCatalogItems: (projectId: string, body: { permission_ids: string[], role_ids: string[] }) =>
      mutation(`/api/identity/projects/${projectId}/access-catalog/import`, { method: 'POST', body }),
    publishProjectPermission: (projectId: string, permissionId: string) =>
      mutation(`/api/identity/projects/${projectId}/permissions/${permissionId}/publish`, { method: 'POST' }),
    publishProjectRole: (projectId: string, roleId: string) =>
      mutation(`/api/identity/projects/${projectId}/roles/${roleId}/publish`, { method: 'POST' }),
    updateProjectRegistration: (
      projectId: string,
      body: { registration_mode: 'invite_only' | 'public', registration_role_id: string | null, email_verification_required: boolean }
    ) => mutation(`/api/identity/projects/${projectId}/registration`, { method: 'PATCH', body }),
    updateProjectEnvironment: (
      projectId: string,
      body: { mode: 'live' | 'sandbox', sandbox_ttl_minutes: number }
    ) => mutation(`/api/identity/projects/${projectId}/environment`, { method: 'PATCH', body }),
    createWebhook: (projectId: string, body: { url: string, events: string[] }) =>
      mutation<IdentityWebhook>(`/api/identity/projects/${projectId}/webhooks`, { method: 'POST', body }),
    updateWebhook: (projectId: string, webhookId: string, body: { url: string, events: string[], status: 'active' | 'disabled' }) =>
      mutation(`/api/identity/projects/${projectId}/webhooks/${webhookId}`, { method: 'PUT', body }),
    rotateWebhookSecret: (projectId: string, webhookId: string) =>
      mutation<IdentityWebhook>(`/api/identity/projects/${projectId}/webhooks/${webhookId}/rotate-secret`, { method: 'POST' }),
    removeWebhook: (projectId: string, webhookId: string) =>
      mutation(`/api/identity/projects/${projectId}/webhooks/${webhookId}`, { method: 'DELETE' }),
    updateUser: (userId: string, body: { is_system_admin: boolean, locked: boolean }) =>
      mutation(`/api/identity/users/${userId}`, { method: 'PATCH', body }),
    updateAccount: (body: { username: string, email: string, avatar_url: string | null }) =>
      mutation<Record<string, unknown>>('/api/identity/account', { method: 'PATCH', body }),
    updateAccountSecurity: (body: { two_factor_enabled: boolean, login_alerts_enabled: boolean, backup_email: string | null }) =>
      mutation<Record<string, unknown>>('/api/identity/account/security', { method: 'PATCH', body }),
    changePassword: (body: { current_password: string, password: string, password_confirmation: string }) =>
      mutation<{ message: string }>('/api/identity/account/password', { method: 'PATCH', body }),
    revokeAccountSession: (sessionId: string) =>
      mutation(`/api/auth/sessions/${sessionId}`, { method: 'DELETE' }),
    createClient: (projectId: string, name: string) =>
      mutation<IdentityClient>(`/api/identity/projects/${projectId}/clients`, { method: 'POST', body: { name } }),
    createHostedApplication: (
      projectId: string,
      body: { name: string, key: string, primary_client_id: string, sandbox_client_id: string | null, application_url: string, callback_url: string, authentication_mode: 'hosted', policy_revision?: string | null, authentication: IdentityHostedApplication['authentication'] }
    ) => mutation<IdentityHostedApplication>(`/api/identity/projects/${projectId}/applications`, { method: 'POST', body }),
    updateHostedApplication: (
      projectId: string,
      applicationId: string,
      body: { name: string, primary_client_id: string, sandbox_client_id: string | null, application_url: string, callback_url: string, authentication_mode: 'hosted', policy_revision?: string | null, status: 'active' | 'disabled', authentication: IdentityHostedApplication['authentication'] }
    ) => mutation(`/api/identity/projects/${projectId}/applications/${applicationId}`, { method: 'PATCH', body }),
    uploadHostedApplicationLogo: (projectId: string, applicationId: string, logo: File) => {
      const formData = new FormData()
      formData.set('logo', logo)
      return mutation<IdentityHostedApplication>(`/api/identity/projects/${projectId}/hosted-applications/${applicationId}/logo`, { method: 'POST', body: formData })
    },
    removeHostedApplicationLogo: (projectId: string, applicationId: string) =>
      mutation(`/api/identity/projects/${projectId}/hosted-applications/${applicationId}/logo`, { method: 'DELETE' }),
    removeHostedApplication: (projectId: string, applicationId: string) =>
      mutation(`/api/identity/projects/${projectId}/applications/${applicationId}`, { method: 'DELETE' }),
    rotateClientSecret: (projectId: string, clientId: string) =>
      mutation<IdentityClient>(`/api/identity/projects/${projectId}/clients/${clientId}/rotate-secret`, { method: 'POST' }),
    renameClient: (projectId: string, clientId: string, name: string) =>
      mutation<IdentityClient>(`/api/identity/projects/${projectId}/clients/${clientId}/name`, { method: 'PATCH', body: { name } }),
    setClientStatus: (projectId: string, clientId: string, status: 'active' | 'disabled') =>
      mutation(`/api/identity/projects/${projectId}/clients/${clientId}`, { method: 'PATCH', body: { status } }),
    removeClient: (projectId: string, clientId: string, confirmation: string) =>
      mutation(`/api/identity/projects/${projectId}/clients/${clientId}`, { method: 'DELETE', body: { confirmation } }),
    createRole: (projectId: string, body: { name: string, slug: string, description?: string | null }) =>
      mutation<IdentityRole>(`/api/identity/projects/${projectId}/roles`, { method: 'POST', body }),
    removeRole: (projectId: string, roleId: string, confirmation: string) =>
      mutation(`/api/identity/projects/${projectId}/roles/${roleId}`, { method: 'DELETE', body: { confirmation } }),
    createPermission: (projectId: string, body: { key: string, name?: string | null, description?: string | null }) =>
      mutation(`/api/identity/projects/${projectId}/permissions`, { method: 'POST', body }),
    removePermission: (projectId: string, permissionId: string, confirmation: string) =>
      mutation(`/api/identity/projects/${projectId}/permissions/${permissionId}`, { method: 'DELETE', body: { confirmation } }),
    syncPermissionManifest: (
      projectId: string,
      clientId: string,
      permissions: Array<{ key: string, name?: string, description?: string }>
    ) => mutation(`/api/identity/projects/${projectId}/clients/${clientId}/permission-manifest`, {
      method: 'PUT',
      body: { permissions }
    }),
    setRolePermissions: (projectId: string, roleId: string, permissionIds: string[]) =>
      mutation(`/api/identity/projects/${projectId}/roles/${roleId}/permissions`, {
        method: 'PUT',
        body: { permission_ids: permissionIds }
      }),
    setMembershipAccess: (
      projectId: string,
      membershipId: string,
      body: { role_ids: string[], permission_ids: string[], is_admin: boolean, status: 'active' | 'suspended' }
    ) => mutation(`/api/identity/projects/${projectId}/memberships/${membershipId}/access`, { method: 'PUT', body }),
    removeMembership: (projectId: string, membershipId: string) =>
      mutation(`/api/identity/projects/${projectId}/memberships/${membershipId}`, { method: 'DELETE' }),
    invite: (projectId: string, body: { email: string, is_admin: boolean }) =>
      mutation<Record<string, unknown>>(`/api/identity/projects/${projectId}/invitations`, { method: 'POST', body }),
    logout: () => mutation<{ success: boolean }>('/api/auth/logout', { method: 'POST' })
  }
}

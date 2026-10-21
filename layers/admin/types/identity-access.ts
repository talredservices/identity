export type IdentityProject = {
  id: string
  name: string
  slug: string
  description: string | null
  status: 'active' | 'suspended' | 'pending_deletion'
  deletion_scheduled_at: string | null
  mode: 'live' | 'sandbox'
  sandbox_ttl_minutes: number
  registration_mode: 'invite_only' | 'public'
  registration_role_id: string | null
  email_verification_required: boolean
}

export type IdentityClient = {
  id: string
  project_id: string
  name: string
  secret_prefix: string
  status: 'active' | 'disabled'
  last_used_at: string | null
  client_secret?: string
}

export type IdentityPermission = {
  id: string
  project_id: string
  key: string
  name: string
  description: string | null
  source: 'manual' | 'manifest' | 'catalog'
  source_client_id: string | null
  catalog_permission_id: string | null
  catalog_version: number | null
  catalog_origin: 'imported' | 'published' | null
  status: 'active' | 'stale'
}

export type IdentityRole = {
  id: string
  project_id: string
  name: string
  slug: string
  description: string | null
  catalog_role_id: string | null
  catalog_version: number | null
  catalog_origin: 'imported' | 'published' | null
  permission_ids: string[]
}

export type IdentityWebhook = {
  id: string
  project_id: string
  url: string
  events: Array<'identity.user.expired' | 'identity.user.deletion_requested'>
  secret_prefix: string
  status: 'active' | 'disabled'
  last_delivered_at: string | null
  secret?: string
}

export type IdentityHostedApplication = {
  id: string
  project_id: string
  primary_client_id: string
  sandbox_client_id: string | null
  key: string
  name: string
  application_url: string
  callback_url: string
  authentication_mode?: 'hosted' | 'app'
  password_reset_url?: string | null
  policy_revision?: string | null
  auth_page_set: string
  appearance: {
    welcome_text: string | null
    accent_color: string | null
    background_preset: 'identity' | 'slate' | 'indigo' | 'emerald' | 'sunset'
    logo_url: string | null
  }
  authentication: {
    google_enabled: boolean
    demo_only: boolean
    terms_required: boolean
    terms_url: string | null
    privacy_url: string | null
  }
  status: 'active' | 'disabled'
}

export type IdentityApplicationListItem = {
  application: IdentityHostedApplication
  project: IdentityProject
  clients: IdentityClient[]
}

export type IdentityMembership = {
  id: string
  project_id: string
  user: { id: string, email?: string, username?: string }
  status: 'active' | 'suspended'
  is_admin: boolean
  authorization_version: number
  role_ids: string[]
  direct_permission_ids: string[]
  roles: string[]
  permissions: string[]
}

export type IdentityProjectDetails = IdentityProject & {
  clients: IdentityClient[]
  memberships: IdentityMembership[]
  roles: IdentityRole[]
  permissions: IdentityPermission[]
  webhooks: IdentityWebhook[]
  hosted_applications: IdentityHostedApplication[]
}

export type IdentityAccessCatalogPermission = {
  id: string
  key: string
  name: string
  description: string | null
  status: 'active' | 'archived'
  version: number
}

export type IdentityAccessCatalogRole = {
  id: string
  slug: string
  name: string
  description: string | null
  status: 'active' | 'archived'
  version: number
  permission_ids: string[]
}

export type IdentityAccessCatalog = {
  permissions: IdentityAccessCatalogPermission[]
  roles: IdentityAccessCatalogRole[]
}

export type IdentityAccessDependencyConflict = {
  code: 'identity.access_dependency_conflict'
  message: string
  resource_type: 'role' | 'permission'
  resource_id: string
  dependencies: {
    memberships: Array<{ id: string, user_id: string, label: string, status: 'active' | 'suspended' }>
    roles: Array<{ id: string, name: string, slug: string }>
    registration_default: boolean
    manifest_client: { id: string, name: string } | null
  }
}

export type {
  IdentityBrowserSession,
  IdentityLoginData
} from '../../auth/shared/types/identity-auth'

export type IdentityAccountSession = {
  id: string
  current: boolean
  project: IdentityProject | null
  client: IdentityClient | null
  created_at: string | null
  expires_at: string
}

export type IdentityAuditEvent = {
  id: string
  event: string
  actor_user_id: string | null
  client_id: string | null
  target_type: string | null
  target_id: string | null
  metadata: Record<string, unknown>
  ip_address: string | null
  created_at: string | null
}

export type IdentityInstallationUser = {
  id: string
  email: string
  username: string
  email_verified_at: string | null
  is_system_admin: boolean
  locked: boolean
  project_count: number
  created_at: string | null
}

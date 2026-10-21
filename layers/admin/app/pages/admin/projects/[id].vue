<script setup lang="ts">
import type { DropdownMenuItem, NavigationMenuItem, TableColumn } from '@nuxt/ui'
import type {
  IdentityAccessCatalog,
  IdentityAccessDependencyConflict,
  IdentityApplicationListItem,
  IdentityAuditEvent,
  IdentityClient,
  IdentityHostedApplication,
  IdentityMembership,
  IdentityPermission,
  IdentityRole,
  IdentityWebhook
} from '#admin/types/identity-access'
import { formatIdentityDate } from '#admin/app/utils/identity-formatters'

definePageMeta({ layout: 'identity-admin', middleware: ['identity-admin'] })

type ProjectTab = 'overview' | 'members' | 'access' | 'clients' | 'applications' | 'webhooks' | 'audit' | 'settings'
type AccessRoleRow = {
  id: string
  name: string
  slug: string
  permissionCount: number
  global: boolean
  availableCatalog: boolean
  projectRole: IdentityRole | null
}
type AccessDeletionTarget
  = { kind: 'role', resource: IdentityRole }
    | { kind: 'permission', resource: IdentityPermission }

const route = useRoute()
const router = useRouter()
const localePath = useLocalePath()
const projectId = computed(() => String(route.params.id))
const access = useIdentityAccess()
const { data: project, status, error, refresh } = await access.project(projectId)
const { data: availableProjects, status: availableProjectsStatus } = await access.projects()
const selectedProjectId = useIdentitySelectedProject()
const { data: session } = await access.session()
const catalogRequest = await access.projectAccessCatalog()
const auditRequest = await access.audit(projectId, { immediate: false })
const toast = useToast()
const rawRouteTab = Array.isArray(route.query.tab) ? route.query.tab[0] : route.query.tab
const routeTab = rawRouteTab === 'hosted-applications' ? 'applications' : rawRouteTab
const validTabs: ProjectTab[] = ['overview', 'members', 'access', 'clients', 'applications', 'webhooks', 'audit', 'settings']
const activeTab = ref<ProjectTab>(validTabs.includes(routeTab as ProjectTab) ? routeTab as ProjectTab : 'overview')
const auditLoaded = ref(false)
const auditVisibleCount = ref(20)
const auditSentinel = ref<HTMLElement | null>(null)
const clientModalOpen = ref(false)
const clientRenameModalOpen = ref(false)
const clientDeletionModalOpen = ref(false)
const clientDeletionConfirmation = ref('')
const clientDeletionSaving = ref(false)
const clientRenameName = ref('')
const clientRenameSaving = ref(false)
const selectedClientForDeletion = ref<IdentityClient | null>(null)
const selectedClientForRename = ref<IdentityClient | null>(null)
const hostedApplicationModalOpen = ref(false)
const invitationModalOpen = ref(false)
const roleModalOpen = ref(false)
const permissionModalOpen = ref(false)
const webhookModalOpen = ref(false)
const deletionModalOpen = ref(false)
const deletionConfirmation = ref('')
const deletionSaving = ref(false)
const suspensionModalOpen = ref(false)
const suspensionConfirmation = ref('')
const suspensionSaving = ref(false)
const clientName = ref('')
const hostedApplication = reactive<{
  id: string | null
  name: string
  key: string
  primaryClientId: string
  sandboxClientId: string
  applicationUrl: string
  callbackUrl: string
  policyRevision: string
  googleEnabled: boolean
  demoOnly: boolean
  termsRequired: boolean
  termsUrl: string
  privacyUrl: string
  status: 'active' | 'disabled'
}>({ id: null, name: '', key: '', primaryClientId: '', sandboxClientId: '', applicationUrl: '', callbackUrl: '', policyRevision: '', googleEnabled: false, demoOnly: false, termsRequired: false, termsUrl: '', privacyUrl: '', status: 'active' })
const role = reactive({ name: '', slug: '', description: '' })
const permissionForm = reactive({ key: '', name: '', description: '' })
const invitation = reactive({ email: '', hosted_application_id: '', is_admin: false })
const registration = reactive<{ mode: 'invite_only' | 'public', roleId: string | null, emailVerificationRequired: boolean }>({ mode: 'invite_only', roleId: null, emailVerificationRequired: true })
const environment = reactive<{ mode: 'live' | 'sandbox', ttlMinutes: number }>({ mode: 'live', ttlMinutes: 60 })
const webhookForm = reactive({
  url: '',
  events: ['identity.user.expired'] as Array<'identity.user.expired' | 'identity.user.deletion_requested'>
})
const revealedWebhookSecret = ref<{ id: string, secret: string } | null>(null)
const revealedSecret = ref<{ clientId: string, secret: string } | null>(null)
const selectedRole = ref<{ id: string, name: string, permissionIds: string[] } | null>(null)
const selectedAccessDeletion = ref<AccessDeletionTarget | null>(null)
const accessDeletionConfirmation = ref('')
const accessDeletionSaving = ref(false)
const accessDeletionServerMessage = ref('')
const selectedMembership = ref<{
  id: string
  label: string
  roleIds: string[]
  permissionIds: string[]
  isAdmin: boolean
  status: 'active' | 'suspended'
} | null>(null)

const tabs = computed(() => [
  { value: 'overview', label: 'Overview', icon: 'i-lucide-layout-dashboard' },
  { value: 'members', label: 'Members', icon: 'i-lucide-users', badge: project.value?.memberships.length ?? 0 },
  { value: 'access', label: 'Roles & permissions', icon: 'i-lucide-shield-check' },
  { value: 'clients', label: 'Clients', icon: 'i-lucide-server-cog', badge: project.value?.clients.length ?? 0 },
  { value: 'applications', label: 'Applications', icon: 'i-lucide-panel-top', badge: project.value?.hosted_applications.length ?? 0 },
  { value: 'webhooks', label: 'Webhooks', icon: 'i-lucide-webhook', badge: project.value?.webhooks.length ?? 0 },
  { value: 'audit', label: 'Audit', icon: 'i-lucide-scroll-text' },
  { value: 'settings', label: 'Settings', icon: 'i-lucide-settings-2' }
])
const projectNavigation = computed<NavigationMenuItem[]>(() => tabs.value.map(item => ({
  label: item.label,
  icon: item.icon,
  badge: item.badge,
  active: activeTab.value === item.value,
  onSelect: () => { activeTab.value = item.value as ProjectTab }
})))
const projectResourceSummary = computed<Array<{ label: string, count: number, icon: string, tab: ProjectTab, detail: string }>>(() => {
  const current = project.value
  if (!current) return []

  return [
    { label: 'Members', count: current.memberships.length, icon: 'i-lucide-users', tab: 'members', detail: 'People with project access' },
    { label: 'Roles', count: current.roles.length, icon: 'i-lucide-shield-check', tab: 'access', detail: 'Reusable access profiles' },
    { label: 'Clients', count: current.clients.length, icon: 'i-lucide-server-cog', tab: 'clients', detail: 'Application credentials' },
    { label: 'Applications', count: current.hosted_applications.length, icon: 'i-lucide-panel-top', tab: 'applications', detail: 'Hosted sign-in experiences' },
    { label: 'Webhooks', count: current.webhooks.length, icon: 'i-lucide-webhook', tab: 'webhooks', detail: 'Lifecycle event destinations' },
    { label: 'Permissions', count: current.permissions.length, icon: 'i-lucide-key-round', tab: 'access', detail: 'Project access capabilities' }
  ]
})
const applicationListItems = computed<IdentityApplicationListItem[]>(() => {
  const currentProject = project.value
  if (!currentProject) return []

  return currentProject.hosted_applications.map(application => ({
    application,
    project: currentProject,
    clients: currentProject.clients.filter(client => (
      client.id === application.primary_client_id || client.id === application.sandbox_client_id
    ))
  }))
})
const projectSwitcherItems = computed(() => {
  const projects = [...(availableProjects.value ?? [])]
  const currentProject = project.value
  if (currentProject && !projects.some(item => item.id === currentProject.id)) {
    projects.push(currentProject)
  }

  return projects.map(item => ({ label: item.name, value: item.id }))
})
watch(project, (currentProject) => {
  if (currentProject) selectedProjectId.value = currentProject.id
}, { immediate: true })
const projectRoleOptions = computed(() => (project.value?.roles ?? []).map(item => ({ label: item.name, value: item.id })))
const registrationRoleName = computed(() => {
  const currentProject = project.value
  if (!currentProject) return 'No default role'

  return currentProject.roles.find(item => item.id === currentProject.registration_role_id)?.name ?? 'No default role'
})
const projectPermissionOptions = computed(() => (project.value?.permissions ?? []).map(item => ({ label: item.key, value: item.id })))
const primaryClientOptions = computed(() => (project.value?.clients ?? [])
  .filter(item => item.status === 'active')
  .map(item => ({ label: `${item.name} (${item.secret_prefix})`, value: item.id })))
const auditEvents = computed(() => auditRequest.data.value ?? [])
const catalog = computed<IdentityAccessCatalog | null>(() => catalogRequest?.data.value ?? null)
const projectPermissions = computed(() => project.value?.permissions ?? [])
const configurableProjectPermissions = computed(() => projectPermissions.value.filter(item => item.catalog_origin !== 'imported'))

async function switchProject(nextProjectId: string | undefined) {
  if (!nextProjectId || nextProjectId === projectId.value) return

  selectedProjectId.value = nextProjectId
  await router.push({
    path: localePath(`/admin/projects/${nextProjectId}`),
    query: route.query
  })
}

const roleRows = computed<AccessRoleRow[]>(() => {
  const projectCatalogRoleIds = new Set((project.value?.roles ?? [])
    .filter(item => item.catalog_role_id)
    .map(item => item.catalog_role_id))
  const reusableRoles = (catalog.value?.roles ?? [])
    .filter(item => !projectCatalogRoleIds.has(item.id))
    .map(item => ({ id: `catalog:${item.id}`, name: item.name, slug: item.slug, permissionCount: item.permission_ids.length, global: true, availableCatalog: true, projectRole: null }))
  const localRoles = (project.value?.roles ?? [])
    .map(item => ({ id: `project:${item.id}`, name: item.name, slug: item.slug, permissionCount: item.permission_ids.length, global: item.catalog_role_id !== null, availableCatalog: false, projectRole: item }))

  return [...reusableRoles, ...localRoles]
})
const registrationRoleOptions = computed(() => [
  { label: 'No default role', value: null },
  ...(catalog.value?.roles ?? []).map(item => ({ label: `${item.name} · Catalog`, value: `catalog:${item.id}` })),
  ...(project.value?.roles ?? [])
    .filter(item => item.catalog_origin !== 'imported')
    .map(item => ({ label: item.name, value: `project:${item.id}` }))
])
const rolePermissionChoices = computed(() => {
  const publishedCatalogPermissionIds = new Set((project.value?.permissions ?? [])
    .filter(item => item.catalog_origin === 'published' && item.catalog_permission_id)
    .map(item => item.catalog_permission_id))
  return [
    ...(catalog.value?.permissions ?? [])
      .filter(item => !publishedCatalogPermissionIds.has(item.id))
      .map(item => ({ value: `catalog:${item.id}`, label: item.key, global: true })),
    ...configurableProjectPermissions.value.map(item => ({ value: `project:${item.id}`, label: item.key, global: item.catalog_permission_id !== null }))
  ]
})
const accessDeletionMemberships = computed(() => {
  const target = selectedAccessDeletion.value
  if (!target || !project.value) return []
  return project.value.memberships.filter(membership => target.kind === 'role'
    ? membership.role_ids.includes(target.resource.id)
    : membership.direct_permission_ids.includes(target.resource.id))
})
const accessDeletionRoles = computed(() => {
  const target = selectedAccessDeletion.value
  if (!target || target.kind !== 'permission' || !project.value) return []
  return project.value.roles.filter(role => role.permission_ids.includes(target.resource.id))
})
const accessDeletionIsRegistrationDefault = computed(() => {
  const target = selectedAccessDeletion.value
  return target?.kind === 'role' && project.value?.registration_role_id === target.resource.id
})
const accessDeletionManifestClient = computed(() => {
  const target = selectedAccessDeletion.value
  if (!target || target.kind !== 'permission' || target.resource.source !== 'manifest' || target.resource.status !== 'active') return null
  return project.value?.clients.find(client => client.id === target.resource.source_client_id) ?? null
})
const accessDeletionBlocked = computed(() => accessDeletionMemberships.value.length > 0
  || accessDeletionRoles.value.length > 0
  || accessDeletionIsRegistrationDefault.value
  || accessDeletionManifestClient.value !== null)
const accessDeletionExpectedConfirmation = computed(() => {
  const target = selectedAccessDeletion.value
  return target?.kind === 'role' ? target.resource.slug : target?.resource.key ?? ''
})
const visibleAuditEvents = computed(() => auditEvents.value.slice(0, auditVisibleCount.value))
const hasMoreAuditEvents = computed(() => auditVisibleCount.value < auditEvents.value.length)
const canManageDeletion = computed(() => session.value?.isSystemAdmin === true)
const projectChangesLocked = computed(() => project.value?.status !== 'active')
const projectChangesLockMessage = computed(() => project.value?.status === 'suspended'
  ? 'This project is suspended. Reactivate it before making configuration changes.'
  : 'This project is scheduled for deletion. Cancel deletion before making configuration changes.')

const memberColumns: TableColumn<IdentityMembership>[] = [
  { accessorKey: 'user', header: 'Member' },
  { accessorKey: 'roles', header: 'Roles' },
  { accessorKey: 'permissions', header: 'Effective permissions' },
  { accessorKey: 'status', header: 'Status' },
  { id: 'actions', header: '' }
]
const roleColumns: TableColumn<AccessRoleRow>[] = [
  { accessorKey: 'name', header: 'Role' },
  { accessorKey: 'permissionCount', header: 'Permissions' },
  { id: 'actions', header: '' }
]
const permissionColumns: TableColumn<IdentityPermission>[] = [
  { accessorKey: 'key', header: 'Permission key' },
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'source', header: 'Source' },
  { accessorKey: 'status', header: 'Status' },
  { id: 'actions', header: '' }
]
const clientColumns: TableColumn<IdentityClient>[] = [
  { accessorKey: 'name', header: 'Client' },
  { accessorKey: 'secret_prefix', header: 'Credential' },
  { accessorKey: 'last_used_at', header: 'Last used' },
  { accessorKey: 'status', header: 'Status' },
  { id: 'actions', header: '' }
]
const selectedClientDependencies = computed(() => (project.value?.hosted_applications ?? []).filter(application => (
  application.primary_client_id === selectedClientForDeletion.value?.id
  || application.sandbox_client_id === selectedClientForDeletion.value?.id
)))
const webhookColumns: TableColumn<IdentityWebhook>[] = [
  { accessorKey: 'url', header: 'Endpoint' },
  { accessorKey: 'events', header: 'Events' },
  { accessorKey: 'last_delivered_at', header: 'Last delivery' },
  { accessorKey: 'status', header: 'Status' },
  { id: 'actions', header: '' }
]
const auditColumns: TableColumn<IdentityAuditEvent>[] = [
  { accessorKey: 'event', header: 'Event' },
  { accessorKey: 'target_type', header: 'Target' },
  { accessorKey: 'actor_user_id', header: 'Actor' },
  { accessorKey: 'ip_address', header: 'IP address' },
  { accessorKey: 'created_at', header: 'Date' }
]

watch(project, (value) => {
  if (!value) return
  registration.mode = value.registration_mode
  const registrationRole = value.roles.find(item => item.id === value.registration_role_id)
  registration.roleId = registrationRole?.catalog_origin === 'imported' && registrationRole.catalog_role_id
    ? `catalog:${registrationRole.catalog_role_id}`
    : value.registration_role_id ? `project:${value.registration_role_id}` : null
  registration.emailVerificationRequired = value.email_verification_required
  environment.mode = value.mode
  environment.ttlMinutes = value.sandbox_ttl_minutes
}, { immediate: true })

watch(activeTab, async (tab) => {
  if (route.query.tab !== tab) {
    await router.replace({ query: { ...route.query, tab } })
  }
  if (tab === 'audit' && !auditLoaded.value) {
    await auditRequest.execute()
    auditLoaded.value = true
  }
}, { immediate: true })

watch(() => route.query.tab, (queryTab) => {
  const candidate = Array.isArray(queryTab) ? queryTab[0] : queryTab
  const normalized = candidate === 'hosted-applications' ? 'applications' : candidate
  if (validTabs.includes(normalized as ProjectTab) && normalized !== activeTab.value) {
    activeTab.value = normalized as ProjectTab
  }
}, { immediate: true })

useIntersectionObserver(auditSentinel, ([entry]) => {
  if (entry?.isIntersecting && activeTab.value === 'audit' && hasMoreAuditEvents.value) {
    auditVisibleCount.value += 20
  }
})

async function refreshProjectAndAudit() {
  await refresh()
  if (auditLoaded.value) await auditRequest.refresh()
}

async function addClient() {
  const client = await access.createClient(projectId.value, clientName.value)
  revealedSecret.value = { clientId: client.id, secret: client.client_secret ?? '' }
  clientName.value = ''
  clientModalOpen.value = false
  await refreshProjectAndAudit()
}

async function rotateClient(clientId: string) {
  const client = await access.rotateClientSecret(projectId.value, clientId)
  revealedSecret.value = { clientId: client.id, secret: client.client_secret ?? '' }
  await refreshProjectAndAudit()
}

function openHostedApplication(application?: IdentityHostedApplication) {
  Object.assign(hostedApplication, application
    ? {
        id: application.id,
        name: application.name,
        key: application.key,
        primaryClientId: application.primary_client_id,
        sandboxClientId: application.sandbox_client_id ?? '',
        applicationUrl: application.application_url,
        callbackUrl: application.callback_url,
        policyRevision: application.policy_revision ?? '',
        googleEnabled: application.authentication.google_enabled,
        demoOnly: application.authentication.demo_only ?? false,
        termsRequired: application.authentication.terms_required,
        termsUrl: application.authentication.terms_url ?? '',
        privacyUrl: application.authentication.privacy_url ?? '',
        status: application.status
      }
    : { id: null, name: '', key: '', primaryClientId: '', sandboxClientId: '', applicationUrl: '', callbackUrl: '', policyRevision: '', googleEnabled: false, demoOnly: false, termsRequired: false, termsUrl: '', privacyUrl: '', status: 'active' })
  hostedApplicationModalOpen.value = true
}

watch(() => hostedApplication.sandboxClientId, (sandboxClientId) => {
  if (!sandboxClientId) hostedApplication.demoOnly = false
})

function hostedApplicationConfigPath(applicationId: string, section: string = 'general') {
  return localePath(`/admin/application-config/${projectId.value}/${applicationId}/${section}`)
}

function applicationConfigPath(application: IdentityHostedApplication) {
  return hostedApplicationConfigPath(application.id)
}

function hostedApplicationAuthentication() {
  return {
    google_enabled: hostedApplication.googleEnabled,
    demo_only: hostedApplication.demoOnly && Boolean(hostedApplication.sandboxClientId),
    terms_required: hostedApplication.termsRequired,
    terms_url: hostedApplication.termsRequired ? hostedApplication.termsUrl.trim() || null : null,
    privacy_url: hostedApplication.privacyUrl.trim() || null
  }
}

async function saveHostedApplication() {
  if (hostedApplication.id) {
    await access.updateHostedApplication(projectId.value, hostedApplication.id, {
      name: hostedApplication.name,
      primary_client_id: hostedApplication.primaryClientId,
      sandbox_client_id: hostedApplication.sandboxClientId || null,
      application_url: hostedApplication.applicationUrl,
      callback_url: hostedApplication.callbackUrl,
      authentication_mode: 'hosted',
      policy_revision: hostedApplication.policyRevision || null,
      status: hostedApplication.status,
      authentication: hostedApplicationAuthentication()
    })
  } else {
    await access.createHostedApplication(projectId.value, {
      name: hostedApplication.name,
      key: hostedApplication.key,
      primary_client_id: hostedApplication.primaryClientId,
      sandbox_client_id: hostedApplication.sandboxClientId || null,
      application_url: hostedApplication.applicationUrl,
      callback_url: hostedApplication.callbackUrl,
      authentication_mode: 'hosted',
      policy_revision: hostedApplication.policyRevision || null,
      authentication: hostedApplicationAuthentication()
    })
  }

  hostedApplicationModalOpen.value = false
  await refreshProjectAndAudit()
}

async function deleteHostedApplication(applicationId: string) {
  await access.removeHostedApplication(projectId.value, applicationId)
  await refreshProjectAndAudit()
}

async function toggleClient(client: IdentityClient) {
  await access.setClientStatus(projectId.value, client.id, client.status === 'active' ? 'disabled' : 'active')
  await refreshProjectAndAudit()
}

function openClientDeletion(client: IdentityClient) {
  selectedClientForDeletion.value = client
  clientDeletionConfirmation.value = ''
  clientDeletionModalOpen.value = true
}

function openClientRename(client: IdentityClient) {
  selectedClientForRename.value = client
  clientRenameName.value = client.name
  clientRenameModalOpen.value = true
}

function clientDependencies(client: IdentityClient): string[] {
  return (project.value?.hosted_applications ?? [])
    .filter(application => application.primary_client_id === client.id || application.sandbox_client_id === client.id)
    .map(application => application.name)
}

function applicationActionItems(application: IdentityHostedApplication): DropdownMenuItem[][] {
  return [[{
    label: 'Delete application',
    icon: 'i-lucide-trash-2',
    color: 'error',
    disabled: projectChangesLocked.value,
    onSelect: () => deleteHostedApplication(application.id)
  }]]
}

async function deleteClient() {
  const client = selectedClientForDeletion.value
  if (!client) return
  clientDeletionSaving.value = true
  try {
    await access.removeClient(projectId.value, client.id, clientDeletionConfirmation.value)
    clientDeletionModalOpen.value = false
    selectedClientForDeletion.value = null
    clientDeletionConfirmation.value = ''
    toast.add({ title: 'Client deleted', description: 'Its credentials have been revoked and its name is available again.', color: 'success' })
    await refreshProjectAndAudit()
  } finally {
    clientDeletionSaving.value = false
  }
}

async function renameClient() {
  const client = selectedClientForRename.value
  const name = clientRenameName.value.trim()
  if (!client || !name) return

  clientRenameSaving.value = true
  try {
    await access.renameClient(projectId.value, client.id, name)
    clientRenameModalOpen.value = false
    selectedClientForRename.value = null
    clientRenameName.value = ''
    toast.add({ title: 'Client renamed', description: 'The client name was updated without changing its credentials.', color: 'success' })
    await refreshProjectAndAudit()
  } finally {
    clientRenameSaving.value = false
  }
}

async function addRole() {
  await access.createRole(projectId.value, { ...role, description: role.description || null })
  Object.assign(role, { name: '', slug: '', description: '' })
  roleModalOpen.value = false
  await refreshProjectAndAudit()
}

async function addPermission() {
  await access.createPermission(projectId.value, {
    key: permissionForm.key,
    name: permissionForm.name || null,
    description: permissionForm.description || null
  })
  Object.assign(permissionForm, { key: '', name: '', description: '' })
  permissionModalOpen.value = false
  await refreshProjectAndAudit()
}

function openAccessDeletion(target: AccessDeletionTarget) {
  selectedAccessDeletion.value = target
  accessDeletionConfirmation.value = ''
  accessDeletionServerMessage.value = ''
}

function accessDependencyConflict(error: unknown): IdentityAccessDependencyConflict | null {
  const candidate = error as {
    data?: { errors?: { public?: IdentityAccessDependencyConflict } }
    response?: { _data?: { errors?: { public?: IdentityAccessDependencyConflict } } }
  }
  const conflict = candidate.data?.errors?.public ?? candidate.response?._data?.errors?.public
  return conflict?.code === 'identity.access_dependency_conflict' ? conflict : null
}

async function deleteSelectedAccessResource() {
  const target = selectedAccessDeletion.value
  if (!target || accessDeletionBlocked.value) return
  accessDeletionSaving.value = true
  accessDeletionServerMessage.value = ''
  try {
    if (target.kind === 'role') {
      await access.removeRole(projectId.value, target.resource.id, accessDeletionConfirmation.value)
    } else {
      await access.removePermission(projectId.value, target.resource.id, accessDeletionConfirmation.value)
    }
    toast.add({ title: target.kind === 'role' ? 'Role deleted' : 'Permission deleted', color: 'success' })
    selectedAccessDeletion.value = null
    accessDeletionConfirmation.value = ''
    await refreshProjectAndAudit()
  } catch (error: unknown) {
    const conflict = accessDependencyConflict(error)
    if (!conflict) throw error
    accessDeletionServerMessage.value = conflict.message
    await refresh()
  } finally {
    accessDeletionSaving.value = false
  }
}

async function publishPermission(permissionId: string) {
  await access.publishProjectPermission(projectId.value, permissionId)
  toast.add({ title: 'Permission published to access catalog', color: 'success' })
  await catalogRequest?.refresh()
  await refreshProjectAndAudit()
}

async function publishRole(roleId: string) {
  await access.publishProjectRole(projectId.value, roleId)
  toast.add({ title: 'Role published to access catalog', color: 'success' })
  await catalogRequest?.refresh()
  await refreshProjectAndAudit()
}

async function sendInvitation() {
  const result = await access.invite(projectId.value, invitation)
  toast.add({
    title: 'Invitation created',
    description: result.invitation_token ? `Development token: ${String(result.invitation_token)}` : undefined,
    duration: result.invitation_token ? 0 : 5000
  })
  Object.assign(invitation, { email: '', hosted_application_id: '', is_admin: false })
  invitationModalOpen.value = false
  await refreshProjectAndAudit()
}

async function saveRegistrationPolicy() {
  let registrationRoleId: string | null = null
  if (registration.mode === 'public' && registration.roleId?.startsWith('catalog:')) {
    const catalogRoleId = registration.roleId.slice('catalog:'.length)
    await access.importCatalogItems(projectId.value, { permission_ids: [], role_ids: [catalogRoleId] })
    await refresh()
    const catalogRole = catalog.value?.roles.find(item => item.id === catalogRoleId)
    registrationRoleId = project.value?.roles.find(item => item.catalog_role_id === catalogRoleId || item.slug === catalogRole?.slug)?.id ?? null
  } else if (registration.mode === 'public' && registration.roleId?.startsWith('project:')) {
    registrationRoleId = registration.roleId.slice('project:'.length)
  }
  await access.updateProjectRegistration(projectId.value, {
    registration_mode: registration.mode,
    registration_role_id: registrationRoleId,
    email_verification_required: registration.emailVerificationRequired
  })
  toast.add({ title: 'Registration policy saved', color: 'success' })
  await refreshProjectAndAudit()
}

async function saveEnvironment() {
  await access.updateProjectEnvironment(projectId.value, {
    mode: environment.mode,
    sandbox_ttl_minutes: environment.ttlMinutes
  })
  toast.add({ title: 'Project environment saved', color: 'success' })
  await refreshProjectAndAudit()
}

async function scheduleDeletion() {
  if (!project.value) return
  deletionSaving.value = true
  try {
    await access.scheduleProjectDeletion(projectId.value, deletionConfirmation.value)
    deletionModalOpen.value = false
    deletionConfirmation.value = ''
    toast.add({ title: 'Project deletion scheduled', description: 'Access was revoked immediately. You can cancel before the purge deadline.', color: 'warning' })
    await refreshProjectAndAudit()
  } finally {
    deletionSaving.value = false
  }
}

async function cancelDeletion() {
  if (!project.value) return
  deletionSaving.value = true
  try {
    await access.cancelProjectDeletion(projectId.value)
    toast.add({ title: 'Project deletion cancelled', color: 'success' })
    await refreshProjectAndAudit()
  } finally {
    deletionSaving.value = false
  }
}

async function suspendProject() {
  if (!project.value) return
  suspensionSaving.value = true
  try {
    await access.suspendProject(projectId.value, suspensionConfirmation.value)
    suspensionModalOpen.value = false
    suspensionConfirmation.value = ''
    toast.add({ title: 'Project suspended', description: 'All active project sessions were revoked immediately.', color: 'warning' })
    await refreshProjectAndAudit()
  } finally {
    suspensionSaving.value = false
  }
}

async function reactivateProject() {
  if (!project.value) return
  suspensionSaving.value = true
  try {
    await access.reactivateProject(projectId.value)
    toast.add({ title: 'Project reactivated', description: 'Users can authenticate again with new sessions.', color: 'success' })
    await refreshProjectAndAudit()
  } finally {
    suspensionSaving.value = false
  }
}

async function addWebhook() {
  const webhook = await access.createWebhook(projectId.value, webhookForm)
  revealedWebhookSecret.value = { id: webhook.id, secret: webhook.secret ?? '' }
  webhookForm.url = ''
  webhookForm.events = ['identity.user.expired']
  webhookModalOpen.value = false
  await refreshProjectAndAudit()
}

async function toggleWebhook(webhook: IdentityWebhook) {
  await access.updateWebhook(projectId.value, webhook.id, {
    url: webhook.url,
    events: webhook.events,
    status: webhook.status === 'active' ? 'disabled' : 'active'
  })
  await refreshProjectAndAudit()
}

async function rotateWebhook(webhookId: string) {
  const webhook = await access.rotateWebhookSecret(projectId.value, webhookId)
  revealedWebhookSecret.value = { id: webhook.id, secret: webhook.secret ?? '' }
  await refreshProjectAndAudit()
}

async function deleteWebhook(webhookId: string) {
  await access.removeWebhook(projectId.value, webhookId)
  await refreshProjectAndAudit()
}

async function saveRolePermissions() {
  if (!selectedRole.value) return
  const catalogPermissionIds = selectedRole.value.permissionIds
    .filter(item => item.startsWith('catalog:'))
    .map(item => item.slice('catalog:'.length))
  if (catalogPermissionIds.length > 0) {
    await access.importCatalogItems(projectId.value, { permission_ids: catalogPermissionIds, role_ids: [] })
    await refresh()
  }
  const permissionIds = selectedRole.value.permissionIds.flatMap((item) => {
    if (item.startsWith('project:')) return [item.slice('project:'.length)]
    const catalogPermissionId = item.slice('catalog:'.length)
    const catalogPermission = catalog.value?.permissions.find(permission => permission.id === catalogPermissionId)
    const projectPermission = project.value?.permissions.find(permission => (
      permission.catalog_permission_id === catalogPermissionId || permission.key === catalogPermission?.key
    ))
    return projectPermission ? [projectPermission.id] : []
  })
  await access.setRolePermissions(projectId.value, selectedRole.value.id, permissionIds)
  selectedRole.value = null
  await refreshProjectAndAudit()
}

async function saveMembership() {
  if (!selectedMembership.value) return
  await access.setMembershipAccess(projectId.value, selectedMembership.value.id, {
    role_ids: selectedMembership.value.roleIds,
    permission_ids: selectedMembership.value.permissionIds,
    is_admin: selectedMembership.value.isAdmin,
    status: selectedMembership.value.status
  })
  selectedMembership.value = null
  await refreshProjectAndAudit()
}

async function removeSelectedMembership() {
  if (!selectedMembership.value) return
  await access.removeMembership(projectId.value, selectedMembership.value.id)
  selectedMembership.value = null
  await refreshProjectAndAudit()
}

function selectRole(item: IdentityRole) {
  const permissionIds = item.permission_ids.map((permissionId) => {
    const permission = project.value?.permissions.find(candidate => candidate.id === permissionId)
    return permission?.catalog_origin === 'imported' && permission.catalog_permission_id
      ? `catalog:${permission.catalog_permission_id}`
      : `project:${permissionId}`
  })
  selectedRole.value = { id: item.id, name: item.name, permissionIds }
}

function selectMembership(membership: IdentityMembership) {
  selectedMembership.value = {
    id: membership.id,
    label: membership.user.username || membership.user.email || membership.user.id,
    roleIds: [...membership.role_ids],
    permissionIds: [...membership.direct_permission_ids],
    isAdmin: membership.is_admin,
    status: membership.status
  }
}
</script>

<template>
  <IdentityPanel
    panel-id="identity-project-detail"
    :title="project?.name || 'Project'"
    icon="i-lucide-folder-key"
    body-class="mx-auto flex w-full max-w-[1600px] flex-col gap-4"
    :back-to="localePath('/admin/projects')"
    back-label="Projects"
  >
    <template #title>
      <USelect
        :model-value="project?.id ?? projectId"
        :items="projectSwitcherItems"
        :loading="availableProjectsStatus === 'pending'"
        aria-label="Select project"
        icon="i-lucide-folder-key"
        size="md"
        class="min-w-0 max-w-64"
        @update:model-value="switchProject"
      />
    </template>

    <IdentityShellState
      v-if="status === 'pending'"
      state="loading"
      title="Loading project"
    />
    <IdentityShellState
      v-else-if="error || !project"
      state="error"
      title="Unable to load project"
      :description="error?.statusMessage || 'The identity service did not return this project and its access configuration.'"
      @retry="refresh()"
    />

    <template v-else>
      <div class="grid min-w-0 gap-5 xl:grid-cols-[14rem_minmax(0,1fr)]">
        <aside
          class="hidden xl:block"
          aria-label="Project navigation"
        >
          <div class="sticky top-4 space-y-3">
            <NuxtLink
              :to="localePath('/admin/projects')"
              class="group block rounded-lg px-3 py-2 transition-colors hover:bg-elevated/60 focus-visible:outline-2 focus-visible:outline-primary"
              aria-label="Back to projects"
            >
              <span class="flex items-center gap-2 text-sm font-medium text-highlighted">
                <UIcon
                  name="i-lucide-arrow-left"
                  class="size-4 shrink-0 text-muted transition-transform group-hover:-translate-x-0.5"
                />
                <span class="truncate">Projects</span>
              </span>
              <span class="mt-1 block pl-6 text-xs text-muted">Back to projects</span>
            </NuxtLink>
            <UNavigationMenu
              :items="projectNavigation"
              orientation="vertical"
              color="neutral"
              variant="pill"
              :ui="{
                link: 'min-h-10 gap-2.5 px-3',
                linkLeadingIcon: 'size-4.5'
              }"
            />
          </div>
        </aside>

        <div class="min-w-0 space-y-5">
          <div class="min-w-0 overflow-x-auto xl:hidden">
            <UTabs
              v-model="activeTab"
              :items="tabs"
              variant="pill"
              :content="false"
              size="lg"
              class="min-w-max"
              :ui="{
                list: 'w-max min-w-max',
                trigger: 'shrink-0 whitespace-nowrap'
              }"
            />
          </div>

          <UAlert
            v-if="revealedSecret"
            color="warning"
            variant="soft"
            title="Store this client secret now"
            :description="`${revealedSecret.clientId}: ${revealedSecret.secret}`"
            icon="i-lucide-key-round"
            close
            @update:open="revealedSecret = null"
          />
          <UAlert
            v-if="revealedWebhookSecret"
            color="warning"
            variant="soft"
            title="Store this webhook signing secret now"
            :description="`${revealedWebhookSecret.id}: ${revealedWebhookSecret.secret}`"
            icon="i-lucide-webhook"
            close
            @update:open="revealedWebhookSecret = null"
          />
          <UAlert
            v-if="projectChangesLocked"
            color="warning"
            variant="soft"
            icon="i-lucide-lock-keyhole"
            title="Project changes are locked"
            :description="projectChangesLockMessage"
          />

          <template v-if="activeTab === 'overview'">
            <div class="flex flex-wrap items-center gap-2 text-sm text-muted">
              <span class="font-mono text-xs">{{ project.slug }}</span>
              <span
                aria-hidden="true"
                class="text-dimmed"
              >·</span>
              <UBadge
                :color="project.mode === 'live' ? 'success' : 'warning'"
                variant="soft"
                class="capitalize"
              >
                {{ project.mode }}
              </UBadge>
              <UBadge
                :color="project.status === 'active' ? 'success' : project.status === 'suspended' ? 'warning' : 'error'"
                variant="soft"
                class="capitalize"
              >
                {{ project.status === 'pending_deletion' ? 'Pending deletion' : project.status }}
              </UBadge>
            </div>
            <UAlert
              v-if="project.status === 'pending_deletion'"
              color="warning"
              variant="soft"
              icon="i-lucide-triangle-alert"
              title="Project deletion is scheduled"
              :description="`Identity access is disabled. This project will be permanently purged after ${formatIdentityDate(project.deletion_scheduled_at)}.`"
            />
            <UAlert
              v-else-if="project.status === 'suspended'"
              color="warning"
              variant="soft"
              icon="i-lucide-pause-circle"
              title="Project is suspended"
              description="Identity access is disabled and active project sessions have been revoked. Reactivate the project to resume access and configuration changes."
            />
            <section
              aria-label="Project resources"
              class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
            >
              <button
                v-for="item in projectResourceSummary"
                :key="item.label"
                type="button"
                class="group flex min-h-24 items-center justify-between gap-4 rounded-xl border border-default bg-default p-4 text-left shadow-xs transition hover:border-primary/40 hover:bg-elevated/60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                @click="activeTab = item.tab"
              >
                <div class="flex min-w-0 items-center gap-3">
                  <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                    <UIcon
                      :name="item.icon"
                      class="size-5"
                    />
                  </span>
                  <span class="min-w-0">
                    <span class="block font-medium text-highlighted">{{ item.label }}</span>
                    <span class="mt-0.5 block text-xs text-muted">{{ item.detail }}</span>
                  </span>
                </div>
                <span class="flex items-center gap-2 text-lg font-semibold tabular-nums text-highlighted">
                  {{ item.count }}
                  <UIcon
                    name="i-lucide-arrow-right"
                    class="size-4 text-muted transition group-hover:translate-x-0.5 group-hover:text-primary"
                  />
                </span>
              </button>
            </section>

            <div class="grid gap-5 xl:grid-cols-[1.4fr_1fr]">
              <UPageCard
                title="Configuration at a glance"
                description="Current environment and sign-up controls for this project."
                variant="subtle"
              >
                <div class="grid gap-4 sm:grid-cols-2">
                  <div class="rounded-lg border border-default bg-default p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted">
                      Environment
                    </p>
                    <div class="mt-2 flex items-center gap-2">
                      <UBadge
                        :color="project.mode === 'live' ? 'success' : 'warning'"
                        variant="soft"
                        class="capitalize"
                      >
                        {{ project.mode }}
                      </UBadge>
                      <span
                        v-if="project.mode === 'sandbox'"
                        class="text-sm text-muted"
                      >{{ project.sandbox_ttl_minutes }} min lifetime</span>
                    </div>
                  </div>
                  <div class="rounded-lg border border-default bg-default p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted">
                      Registration
                    </p>
                    <p class="mt-2 font-medium text-highlighted">
                      {{ project.registration_mode === 'public' ? 'Public registration' : 'Invitation only' }}
                    </p>
                  </div>
                  <div class="rounded-lg border border-default bg-default p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted">
                      Default role
                    </p>
                    <p class="mt-2 font-medium text-highlighted">
                      {{ registrationRoleName }}
                    </p>
                  </div>
                  <div class="rounded-lg border border-default bg-default p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted">
                      Email verification
                    </p>
                    <p class="mt-2 font-medium text-highlighted">
                      {{ project.email_verification_required ? 'Required' : 'Not required' }}
                    </p>
                  </div>
                </div>
                <UButton
                  label="Manage project settings"
                  icon="i-lucide-settings-2"
                  variant="outline"
                  color="neutral"
                  @click="activeTab = 'settings'"
                />
              </UPageCard>

              <UPageCard
                title="Next steps"
                description="Common project operations."
                variant="subtle"
              >
                <div class="divide-y divide-default">
                  <button
                    class="flex w-full items-center justify-between gap-4 py-3 text-left first:pt-0 hover:text-primary"
                    @click="activeTab = 'members'"
                  >
                    <span><span class="block text-sm font-medium text-highlighted">Invite a member</span><span class="text-xs text-muted">Grant a teammate access to this project.</span></span>
                    <UIcon
                      name="i-lucide-arrow-right"
                      class="size-4 shrink-0 text-muted"
                    />
                  </button>
                  <button
                    class="flex w-full items-center justify-between gap-4 py-3 text-left hover:text-primary"
                    @click="activeTab = 'clients'"
                  >
                    <span><span class="block text-sm font-medium text-highlighted">Manage credentials</span><span class="text-xs text-muted">Review clients and rotate secrets.</span></span>
                    <UIcon
                      name="i-lucide-arrow-right"
                      class="size-4 shrink-0 text-muted"
                    />
                  </button>
                  <button
                    class="flex w-full items-center justify-between gap-4 py-3 text-left last:pb-0 hover:text-primary"
                    @click="activeTab = 'audit'"
                  >
                    <span><span class="block text-sm font-medium text-highlighted">Review audit history</span><span class="text-xs text-muted">Inspect recorded project activity.</span></span>
                    <UIcon
                      name="i-lucide-arrow-right"
                      class="size-4 shrink-0 text-muted"
                    />
                  </button>
                </div>
              </UPageCard>
            </div>
          </template>

          <template v-else-if="activeTab === 'settings'">
            <div class="grid gap-5 xl:grid-cols-2 xl:items-start">
              <div class="space-y-5">
                <UPageCard
                  title="Project environment"
                  description="Control runtime mode and sandbox lifetime directly."
                  variant="subtle"
                  class="self-start"
                >
                  <form
                    class="space-y-5"
                    @submit.prevent="saveEnvironment"
                  >
                    <UFormField label="Mode">
                      <USelect
                        v-model="environment.mode"
                        :items="[{ label: 'Live', value: 'live' }, { label: 'Sandbox', value: 'sandbox' }]"
                        class="w-full"
                        :disabled="projectChangesLocked"
                      />
                    </UFormField>

                    <UFormField
                      v-if="environment.mode === 'sandbox'"
                      label="Temporary account lifetime"
                      description="Minutes before Identity expires and removes the sandbox identity."
                    >
                      <UInput
                        v-model.number="environment.ttlMinutes"
                        type="number"
                        :min="5"
                        :max="1440"
                        class="w-full"
                        :disabled="projectChangesLocked"
                      />
                    </UFormField>

                    <UAlert
                      v-if="environment.mode === 'live'"
                      color="success"
                      variant="soft"
                      icon="i-lucide-shield-check"
                      title="Production safeguards enabled"
                      description="Live mode uses the complete credential and verification flow."
                    />

                    <UButton
                      type="submit"
                      label="Save environment"
                      icon="i-lucide-save"
                      :disabled="projectChangesLocked"
                    />
                  </form>
                </UPageCard>

                <UPageCard
                  v-if="canManageDeletion"
                  title="Danger zone"
                  description="Suspend access immediately or schedule permanent deletion. Audit records are retained after the purge."
                  variant="subtle"
                  class="border border-error/30"
                >
                  <div class="space-y-4">
                    <div
                      v-if="project.status !== 'pending_deletion'"
                      class="flex flex-wrap items-center justify-between gap-4"
                    >
                      <p class="text-sm text-muted">
                        {{ project.status === 'suspended' ? 'Identity access is paused. Reactivation does not restore prior sessions.' : 'Suspension revokes every active project session immediately.' }}
                      </p>
                      <UButton
                        v-if="project.status === 'suspended'"
                        label="Reactivate project"
                        icon="i-lucide-play-circle"
                        color="success"
                        variant="outline"
                        :loading="suspensionSaving"
                        @click="reactivateProject"
                      />
                      <UButton
                        v-else
                        label="Suspend project"
                        icon="i-lucide-pause-circle"
                        color="warning"
                        variant="outline"
                        @click="() => { suspensionModalOpen = true }"
                      />
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-4 border-t border-default pt-4">
                      <p class="text-sm text-muted">
                        {{ project.status === 'pending_deletion' ? 'The project can still be restored before its purge deadline.' : 'Permanent removal is delayed for the configured recovery period.' }}
                      </p>
                      <UButton
                        v-if="project.status === 'pending_deletion'"
                        label="Cancel deletion"
                        icon="i-lucide-rotate-ccw"
                        color="warning"
                        :loading="deletionSaving"
                        @click="cancelDeletion"
                      />
                      <UButton
                        v-else
                        label="Schedule deletion"
                        icon="i-lucide-trash-2"
                        color="error"
                        variant="outline"
                        @click="() => { deletionModalOpen = true }"
                      />
                    </div>
                  </div>
                </UPageCard>
              </div>

              <UPageCard
                title="Registration policy"
                description="Control how identities become members of this project."
                variant="subtle"
                class="self-start"
              >
                <form
                  class="space-y-5"
                  @submit.prevent="saveRegistrationPolicy"
                >
                  <UFormField label="Registration">
                    <USelect
                      v-model="registration.mode"
                      :items="[{ label: 'Invitation only', value: 'invite_only' }, { label: 'Public registration', value: 'public' }]"
                      class="w-full"
                      :disabled="projectChangesLocked"
                    />
                  </UFormField>

                  <UFormField
                    v-if="registration.mode === 'public'"
                    label="Default role"
                    description="Optional role assigned to every new member."
                  >
                    <USelect
                      v-model="registration.roleId"
                      :items="registrationRoleOptions"
                      class="w-full"
                      :disabled="projectChangesLocked"
                    />
                  </UFormField>

                  <UFormField
                    label="Email verification"
                    description="Choose whether new public registrations must verify ownership of their email address."
                  >
                    <USelect
                      v-model="registration.emailVerificationRequired"
                      :items="[
                        { label: 'Required', value: true },
                        { label: 'Not required', value: false }
                      ]"
                      class="w-full"
                      :disabled="projectChangesLocked"
                    />
                  </UFormField>

                  <UAlert
                    :color="registration.mode === 'public' ? 'warning' : 'info'"
                    variant="soft"
                    icon="i-lucide-info"
                    :title="registration.mode === 'public' ? 'Public enrollment enabled' : 'Invitation required'"
                    :description="registration.mode === 'public'
                      ? (registration.emailVerificationRequired
                        ? 'Anyone using an approved client can create a project membership after verifying their email.'
                        : 'Anyone using an approved client can create a verified project membership immediately.')
                      : 'New members need a one-time invitation token.'"
                  />

                  <UButton
                    type="submit"
                    label="Save policy"
                    icon="i-lucide-save"
                    :disabled="projectChangesLocked"
                  />
                </form>
              </UPageCard>
            </div>
          </template>

          <template v-else-if="activeTab === 'members'">
            <IdentityTableCard
              title="Project members"
              description="Effective access is the union of roles and direct permission grants."
              :count="project.memberships.length"
            >
              <template #actions>
                <UButton
                  label="Invite member"
                  icon="i-lucide-mail-plus"
                  :disabled="projectChangesLocked"
                  @click="() => { invitationModalOpen = true }"
                />
              </template>
              <UTable
                :data="project.memberships"
                :columns="memberColumns"
                empty="No members belong to this project."
                class="min-w-5xl"
              >
                <template #user-cell="{ row }">
                  <div class="flex items-center gap-3 py-1">
                    <UAvatar
                      :alt="row.original.user.username || row.original.user.email || row.original.user.id"
                      size="sm"
                    />
                    <div class="min-w-0">
                      <p class="truncate font-medium text-highlighted">
                        {{ row.original.user.username || row.original.user.email }}
                      </p>
                      <p class="truncate text-xs text-muted">
                        {{ row.original.user.email }} · authorization v{{ row.original.authorization_version }}
                      </p>
                    </div>
                  </div>
                </template>
                <template #roles-cell="{ row }">
                  <div class="flex flex-wrap gap-1.5">
                    <UBadge
                      v-if="row.original.is_admin"
                      color="primary"
                      variant="soft"
                    >
                      Admin
                    </UBadge>
                    <UBadge
                      v-for="item in row.original.roles.slice(0, 3)"
                      :key="item"
                      color="neutral"
                      variant="soft"
                    >
                      {{ item }}
                    </UBadge>
                    <span
                      v-if="!row.original.roles.length && !row.original.is_admin"
                      class="text-sm text-muted"
                    >No roles</span>
                  </div>
                </template>
                <template #permissions-cell="{ row }">
                  <span class="text-sm text-muted">{{ row.original.permissions.length }} permission{{ row.original.permissions.length === 1 ? '' : 's' }}</span>
                </template>
                <template #status-cell="{ row }">
                  <UBadge
                    :color="row.original.status === 'active' ? 'success' : 'warning'"
                    variant="soft"
                    class="capitalize"
                  >
                    {{ row.original.status }}
                  </UBadge>
                </template>
                <template #actions-cell="{ row }">
                  <div class="flex justify-end">
                    <UButton
                      label="Manage"
                      icon="i-lucide-settings-2"
                      color="neutral"
                      variant="ghost"
                      :disabled="projectChangesLocked"
                      @click="selectMembership(row.original)"
                    />
                  </div>
                </template>
              </UTable>
            </IdentityTableCard>
          </template>

          <template v-else-if="activeTab === 'access'">
            <div class="space-y-5">
              <IdentityTableCard
                title="Roles"
                description="Catalog roles are reusable across projects. Project roles can be configured locally."
                :count="roleRows.length"
              >
                <template #actions>
                  <UButton
                    label="New role"
                    icon="i-lucide-plus"
                    size="sm"
                    :disabled="projectChangesLocked"
                    @click="() => { roleModalOpen = true }"
                  />
                </template>
                <UTable
                  :data="roleRows"
                  :columns="roleColumns"
                  empty="No roles are available yet."
                >
                  <template #name-cell="{ row }">
                    <div class="py-1">
                      <div class="flex items-center gap-2">
                        <p class="font-medium text-highlighted">
                          {{ row.original.name }}
                        </p>
                        <UBadge
                          v-if="row.original.global"
                          color="primary"
                          variant="soft"
                        >
                          {{ row.original.availableCatalog ? 'Available catalog' : 'Catalog' }}
                        </UBadge>
                      </div>
                      <p class="text-xs text-muted">
                        {{ row.original.slug }}
                      </p>
                    </div>
                  </template>
                  <template #permissionCount-cell="{ row }">
                    <span class="text-sm text-muted">{{ row.original.permissionCount }} assigned</span>
                  </template>
                  <template #actions-cell="{ row }">
                    <div
                      v-if="row.original.projectRole"
                      class="flex justify-end gap-1"
                    >
                      <UButton
                        label="Configure"
                        icon="i-lucide-sliders-horizontal"
                        color="neutral"
                        variant="ghost"
                        :disabled="projectChangesLocked"
                        @click="selectRole(row.original.projectRole)"
                      />
                      <UButton
                        v-if="canManageDeletion && !row.original.projectRole.catalog_role_id"
                        label="Publish"
                        icon="i-lucide-upload"
                        color="neutral"
                        variant="ghost"
                        :disabled="projectChangesLocked"
                        @click="publishRole(row.original.projectRole.id)"
                      />
                      <UButton
                        label="Delete"
                        icon="i-lucide-trash-2"
                        color="error"
                        variant="ghost"
                        :disabled="projectChangesLocked"
                        @click="openAccessDeletion({ kind: 'role', resource: row.original.projectRole })"
                      />
                    </div>
                  </template>
                </UTable>
              </IdentityTableCard>

              <IdentityTableCard
                title="Project permissions"
                description="Permissions created by this project. Publishing adds one to the reusable access catalog."
                :count="projectPermissions.length"
              >
                <template #actions>
                  <UButton
                    label="New permission"
                    icon="i-lucide-plus"
                    size="sm"
                    :disabled="projectChangesLocked"
                    @click="() => { permissionModalOpen = true }"
                  />
                </template>
                <UTable
                  :data="projectPermissions"
                  :columns="permissionColumns"
                  empty="No project permissions yet."
                  class="min-w-2xl"
                >
                  <template #key-cell="{ row }">
                    <div class="flex items-center gap-2">
                      <code class="rounded-md bg-elevated px-2 py-1 text-xs">{{ row.original.key }}</code>
                      <UBadge
                        v-if="row.original.catalog_permission_id"
                        color="primary"
                        variant="soft"
                      >
                        Catalog
                      </UBadge>
                    </div>
                  </template>
                  <template #name-cell="{ row }">
                    <span class="text-sm text-muted">{{ row.original.name }}</span>
                  </template>
                  <template #source-cell="{ row }">
                    <UBadge
                      color="neutral"
                      variant="soft"
                      class="capitalize"
                    >
                      {{ row.original.source }}
                    </UBadge>
                  </template>
                  <template #status-cell="{ row }">
                    <UBadge
                      :color="row.original.status === 'active' ? 'success' : 'warning'"
                      variant="soft"
                      class="capitalize"
                    >
                      {{ row.original.status }}
                    </UBadge>
                  </template>
                  <template #actions-cell="{ row }">
                    <div class="flex justify-end gap-1">
                      <UButton
                        v-if="canManageDeletion && !row.original.catalog_permission_id"
                        label="Publish"
                        icon="i-lucide-upload"
                        color="neutral"
                        variant="ghost"
                        :disabled="projectChangesLocked"
                        @click="publishPermission(row.original.id)"
                      />
                      <UButton
                        label="Delete"
                        icon="i-lucide-trash-2"
                        color="error"
                        variant="ghost"
                        :disabled="projectChangesLocked"
                        @click="openAccessDeletion({ kind: 'permission', resource: row.original })"
                      />
                    </div>
                  </template>
                </UTable>
              </IdentityTableCard>
            </div>
          </template>

          <template v-else-if="activeTab === 'clients'">
            <UAlert
              v-if="projectChangesLocked"
              color="warning"
              variant="soft"
              icon="i-lucide-lock-keyhole"
              title="Client management is locked"
              :description="projectChangesLockMessage"
            />
            <IdentityTableCard
              title="Confidential clients"
              description="Create one credential per BFF, API, worker, or environment."
              :count="project.clients.length"
            >
              <template #actions>
                <UButton
                  label="New client"
                  icon="i-lucide-plus"
                  :disabled="projectChangesLocked"
                  @click="() => { clientModalOpen = true }"
                />
              </template>
              <UTable
                :data="project.clients"
                :columns="clientColumns"
                empty="No confidential clients yet."
                class="min-w-5xl"
              >
                <template #name-cell="{ row }">
                  <div class="py-1">
                    <p class="font-medium text-highlighted">
                      {{ row.original.name }}
                    </p>
                    <p class="font-mono text-xs text-muted">
                      {{ row.original.id }}
                    </p>
                  </div>
                </template>
                <template #secret_prefix-cell="{ row }">
                  <code class="text-xs text-muted">{{ row.original.secret_prefix }}••••••••</code>
                </template>
                <template #last_used_at-cell="{ row }">
                  <span class="whitespace-nowrap text-sm text-muted">{{ formatIdentityDate(row.original.last_used_at) }}</span>
                </template>
                <template #status-cell="{ row }">
                  <UBadge
                    :color="row.original.status === 'active' ? 'success' : 'neutral'"
                    variant="soft"
                    class="capitalize"
                  >
                    {{ row.original.status }}
                  </UBadge>
                </template>
                <template #actions-cell="{ row }">
                  <div class="flex justify-end gap-1">
                    <UTooltip text="Rename client">
                      <UButton
                        icon="i-lucide-pencil"
                        color="neutral"
                        variant="ghost"
                        aria-label="Rename client"
                        :disabled="projectChangesLocked"
                        @click="openClientRename(row.original)"
                      />
                    </UTooltip>
                    <UButton
                      :label="row.original.status === 'active' ? 'Disable' : 'Enable'"
                      :icon="row.original.status === 'active' ? 'i-lucide-ban' : 'i-lucide-circle-check'"
                      color="neutral"
                      variant="ghost"
                      :disabled="projectChangesLocked"
                      @click="toggleClient(row.original)"
                    />
                    <UButton
                      label="Rotate"
                      icon="i-lucide-refresh-cw"
                      color="neutral"
                      variant="outline"
                      :disabled="projectChangesLocked"
                      @click="rotateClient(row.original.id)"
                    />
                    <UTooltip :text="projectChangesLocked ? projectChangesLockMessage : (clientDependencies(row.original).length ? `Used by: ${clientDependencies(row.original).join(', ')}` : 'Delete client')">
                      <UButton
                        label="Delete"
                        icon="i-lucide-trash-2"
                        color="error"
                        variant="ghost"
                        :disabled="projectChangesLocked || clientDependencies(row.original).length > 0"
                        @click="openClientDeletion(row.original)"
                      />
                    </UTooltip>
                  </div>
                </template>
              </UTable>
            </IdentityTableCard>
          </template>

          <template v-else-if="activeTab === 'applications'">
            <IdentityTableCard
              title="Applications"
              description="Manage product integrations, confidential clients, approved endpoints and security policy."
              :count="project.hosted_applications.length"
            >
              <template #actions>
                <UButton
                  label="New application"
                  icon="i-lucide-plus"
                  :disabled="projectChangesLocked"
                  @click="openHostedApplication()"
                />
              </template>
              <IdentityApplicationList
                :items="applicationListItems"
                :config-path="applicationConfigPath"
                :disabled="projectChangesLocked"
              >
                <template #actions="{ item }">
                  <UTooltip text="Configure application">
                    <UButton
                      icon="i-lucide-settings-2"
                      color="neutral"
                      variant="outline"
                      square
                      :aria-label="`Configure ${item.application.name}`"
                      :disabled="projectChangesLocked"
                      :to="applicationConfigPath(item.application)"
                    />
                  </UTooltip>
                  <UDropdownMenu
                    :items="applicationActionItems(item.application)"
                    :content="{ align: 'end', collisionPadding: 8 }"
                  >
                    <UTooltip text="More actions">
                      <UButton
                        icon="i-lucide-ellipsis-vertical"
                        color="neutral"
                        variant="ghost"
                        square
                        :aria-label="`More actions for ${item.application.name}`"
                      />
                    </UTooltip>
                  </UDropdownMenu>
                </template>
              </IdentityApplicationList>
            </IdentityTableCard>
          </template>

          <template v-else-if="activeTab === 'webhooks'">
            <IdentityTableCard
              title="Cleanup webhooks"
              description="Signed lifecycle events allow consuming services to erase user-owned data."
              :count="project.webhooks.length"
            >
              <template #actions>
                <UButton
                  label="Add webhook"
                  icon="i-lucide-plus"
                  :disabled="projectChangesLocked"
                  @click="() => { webhookModalOpen = true }"
                />
              </template>
              <UTable
                :data="project.webhooks"
                :columns="webhookColumns"
                empty="No cleanup webhooks configured."
                class="min-w-6xl"
              >
                <template #url-cell="{ row }">
                  <div class="max-w-md py-1">
                    <p class="truncate font-medium text-highlighted">
                      {{ row.original.url }}
                    </p>
                    <p class="text-xs text-muted">
                      Secret {{ row.original.secret_prefix }}••••••••
                    </p>
                  </div>
                </template>
                <template #events-cell="{ row }">
                  <div class="flex flex-wrap gap-1.5">
                    <UBadge
                      v-for="event in row.original.events"
                      :key="event"
                      color="neutral"
                      variant="soft"
                    >
                      {{ event.replace('identity.user.', '') }}
                    </UBadge>
                  </div>
                </template>
                <template #last_delivered_at-cell="{ row }">
                  <span class="whitespace-nowrap text-sm text-muted">{{ formatIdentityDate(row.original.last_delivered_at) }}</span>
                </template>
                <template #status-cell="{ row }">
                  <UBadge
                    :color="row.original.status === 'active' ? 'success' : 'neutral'"
                    variant="soft"
                    class="capitalize"
                  >
                    {{ row.original.status }}
                  </UBadge>
                </template>
                <template #actions-cell="{ row }">
                  <div class="flex justify-end gap-1">
                    <UButton
                      :icon="row.original.status === 'active' ? 'i-lucide-pause' : 'i-lucide-play'"
                      color="neutral"
                      variant="ghost"
                      :aria-label="row.original.status === 'active' ? 'Disable webhook' : 'Enable webhook'"
                      :disabled="projectChangesLocked"
                      @click="toggleWebhook(row.original)"
                    />
                    <UButton
                      icon="i-lucide-refresh-cw"
                      color="neutral"
                      variant="ghost"
                      aria-label="Rotate webhook secret"
                      :disabled="projectChangesLocked"
                      @click="rotateWebhook(row.original.id)"
                    />
                    <UButton
                      icon="i-lucide-trash-2"
                      color="error"
                      variant="ghost"
                      aria-label="Delete webhook"
                      :disabled="projectChangesLocked"
                      @click="deleteWebhook(row.original.id)"
                    />
                  </div>
                </template>
              </UTable>
            </IdentityTableCard>
          </template>

          <template v-else-if="activeTab === 'audit'">
            <IdentityShellState
              v-if="auditRequest.status.value === 'pending'"
              state="loading"
              title="Loading audit history"
            />
            <IdentityShellState
              v-else-if="auditRequest.error.value"
              state="error"
              title="Unable to load audit history"
              :description="auditRequest.error.value.statusMessage || 'The audit stream could not be loaded.'"
              @retry="auditRequest.refresh()"
            />
            <IdentityTableCard
              v-else
              title="Audit history"
              description="Authentication and access-management changes for this project."
              :count="auditEvents.length"
            >
              <UTable
                :data="visibleAuditEvents"
                :columns="auditColumns"
                empty="No audit events recorded yet."
                class="min-w-6xl"
              >
                <template #event-cell="{ row }">
                  <code class="text-xs text-highlighted">{{ row.original.event }}</code>
                </template>
                <template #target_type-cell="{ row }">
                  <div class="max-w-xs">
                    <p class="text-sm">
                      {{ row.original.target_type || 'project' }}
                    </p><p class="truncate font-mono text-xs text-muted">
                      {{ row.original.target_id || projectId }}
                    </p>
                  </div>
                </template>
                <template #actor_user_id-cell="{ row }">
                  <span class="font-mono text-xs text-muted">{{ row.original.actor_user_id || 'System' }}</span>
                </template>
                <template #ip_address-cell="{ row }">
                  <span class="font-mono text-xs text-muted">{{ row.original.ip_address || '—' }}</span>
                </template>
                <template #created_at-cell="{ row }">
                  <time class="whitespace-nowrap text-sm text-muted">{{ formatIdentityDate(row.original.created_at) }}</time>
                </template>
              </UTable>
              <template
                v-if="hasMoreAuditEvents"
                #footer
              >
                <p class="text-sm text-muted">
                  Showing {{ visibleAuditEvents.length }} of {{ auditEvents.length }} events
                </p>
                <UButton
                  label="Load more"
                  icon="i-lucide-chevron-down"
                  color="neutral"
                  variant="ghost"
                  @click="() => { auditVisibleCount += 20 }"
                />
              </template>
            </IdentityTableCard>
            <div
              ref="auditSentinel"
              class="h-px"
              aria-hidden="true"
            />
          </template>
        </div>
      </div>

      <UModal
        v-model:open="clientDeletionModalOpen"
        title="Delete confidential client"
        :description="selectedClientForDeletion ? `Type ${selectedClientForDeletion.name} to permanently delete this client and revoke its credentials.` : ''"
        @update:open="value => { if (!value) selectedClientForDeletion = null }"
      >
        <template #body>
          <form
            v-if="selectedClientForDeletion"
            class="space-y-5"
            @submit.prevent="deleteClient"
          >
            <UAlert
              color="warning"
              variant="soft"
              icon="i-lucide-triangle-alert"
              title="This cannot be undone"
              description="Active sessions and refresh tokens issued by this client will be revoked. Project roles and permissions remain available to other clients."
            />
            <UFormField
              label="Client name"
              :description="`Type ${selectedClientForDeletion.name} exactly to continue.`"
            >
              <UInput
                v-model="clientDeletionConfirmation"
                autofocus
                class="w-full"
              />
            </UFormField>
            <p
              v-if="selectedClientDependencies.length"
              class="text-sm text-error"
            >
              Reassign or remove these hosted applications first: {{ selectedClientDependencies.map(application => application.name).join(', ') }}.
            </p>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { clientDeletionModalOpen = false }"
              />
              <UButton
                type="submit"
                label="Delete client"
                color="error"
                icon="i-lucide-trash-2"
                :disabled="clientDeletionConfirmation !== selectedClientForDeletion.name || selectedClientDependencies.length > 0"
                :loading="clientDeletionSaving"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        v-model:open="clientRenameModalOpen"
        title="Rename confidential client"
        :description="selectedClientForRename ? `Update the display name for ${selectedClientForRename.name}. Credentials and bindings stay unchanged.` : ''"
        @update:open="value => { if (!value) selectedClientForRename = null }"
      >
        <template #body>
          <form
            v-if="selectedClientForRename"
            class="space-y-5"
            @submit.prevent="renameClient"
          >
            <UFormField
              label="Client name"
              required
              description="Use a descriptive name for the service or BFF that owns these credentials."
            >
              <UInput
                v-model="clientRenameName"
                autofocus
                class="w-full"
              />
            </UFormField>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { clientRenameModalOpen = false }"
              />
              <UButton
                type="submit"
                label="Save name"
                icon="i-lucide-check"
                :disabled="!clientRenameName.trim() || clientRenameName.trim() === selectedClientForRename.name"
                :loading="clientRenameSaving"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        v-model:open="clientModalOpen"
        title="Create confidential client"
        description="The secret is shown once after creation."
      >
        <template #body>
          <form
            class="space-y-4"
            @submit.prevent="addClient"
          >
            <UFormField
              label="Client name"
              required
            >
              <UInput
                v-model="clientName"
                autofocus
                placeholder="My client"
                class="w-full"
              />
            </UFormField><div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { clientModalOpen = false }"
              /><UButton
                type="submit"
                label="Create client"
                icon="i-lucide-plus"
              />
            </div>
          </form>
        </template>
      </UModal>
      <USlideover
        v-model:open="hostedApplicationModalOpen"
        :title="hostedApplication.id ? 'Configure application' : 'Create application'"
        description="An application binds a product to Identity. Client secrets remain in the product BFF."
        :ui="{ content: 'sm:max-w-3xl', body: 'overflow-y-auto' }"
      >
        <template #body>
          <form
            class="space-y-5"
            @submit.prevent="saveHostedApplication"
          >
            <div class="space-y-5">
              <section class="rounded-xl border border-default">
                <div class="border-b border-default px-5 py-4">
                  <div class="flex items-center gap-2">
                    <UIcon
                      name="i-lucide-app-window"
                      class="size-4 text-primary"
                    />
                    <h3 class="font-semibold text-highlighted">
                      Integration
                    </h3>
                  </div>
                  <p class="mt-1 text-sm text-muted">
                    Identify the consuming product. Identity serves its code-built authentication and account pages on the public Identity host.
                  </p>
                </div>
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                  <UFormField
                    label="Application name"
                    required
                    class="sm:col-span-2"
                  >
                    <UInput
                      v-model="hostedApplication.name"
                      autofocus
                      placeholder="Example project"
                      class="w-full"
                    />
                  </UFormField>
                  <UFormField
                    label="Application key"
                    description="Stable integration key used by the consuming BFF."
                    required
                    class="sm:col-span-2"
                  >
                    <UInput
                      v-model="hostedApplication.key"
                      :disabled="Boolean(hostedApplication.id)"
                      placeholder="example-application"
                      class="w-full font-mono"
                    />
                  </UFormField>
                </div>
              </section>

              <section class="rounded-xl border border-default">
                <div class="border-b border-default px-5 py-4">
                  <div class="flex items-center gap-2">
                    <UIcon
                      name="i-lucide-shield-check"
                      class="size-4 text-primary"
                    />
                    <h3 class="font-semibold text-highlighted">
                      Security
                    </h3>
                  </div>
                  <p class="mt-1 text-sm text-muted">
                    Identity enforces these policies. Your application owns the legal documents and published policy revision.
                  </p>
                </div>
                <div class="space-y-4 p-5">
                  <UCheckbox
                    v-model="hostedApplication.googleEnabled"
                    label="Offer Google sign-in"
                    description="Requires provider credentials and an approved callback on the Identity API."
                  />
                  <UCheckbox
                    v-model="hostedApplication.termsRequired"
                    label="Require acceptance of terms when creating an account"
                    description="Identity records acceptance of the registered policy revision."
                  />
                  <UCheckbox
                    v-model="hostedApplication.demoOnly"
                    :disabled="!hostedApplication.sandboxClientId"
                    label="Demo sign-in only"
                    description="Requires a linked sandbox client. Disables primary sign-in, account creation, and password recovery for this application."
                  />
                  <UFormField
                    label="Approved policy revision"
                    :required="hostedApplication.termsRequired"
                  >
                    <UInput
                      v-model="hostedApplication.policyRevision"
                      placeholder="2026-10-08"
                      class="w-full"
                    />
                  </UFormField>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <UFormField
                      label="Terms of Service URL"
                      :required="hostedApplication.termsRequired"
                    >
                      <UInput
                        v-model="hostedApplication.termsUrl"
                        type="url"
                        placeholder="https://app.example.com/legal/terms"
                        class="w-full font-mono"
                      />
                    </UFormField>
                    <UFormField label="Privacy Policy URL">
                      <UInput
                        v-model="hostedApplication.privacyUrl"
                        type="url"
                        placeholder="https://app.example.com/legal/privacy"
                        class="w-full font-mono"
                      />
                    </UFormField>
                  </div>
                </div>
              </section>

              <section class="rounded-xl border border-default">
                <div class="border-b border-default px-5 py-4">
                  <div class="flex items-center gap-2">
                    <UIcon
                      name="i-lucide-key-round"
                      class="size-4 text-primary"
                    />
                    <h3 class="font-semibold text-highlighted">
                      Client binding
                    </h3>
                  </div>
                  <p class="mt-1 text-sm text-muted">
                    Bind the confidential clients Identity uses internally. Their secrets never enter this form.
                  </p>
                </div>
                <div class="grid gap-4 p-5">
                  <UFormField
                    label="Primary client"
                    description="An active client from this project."
                    required
                  >
                    <USelect
                      v-model="hostedApplication.primaryClientId"
                      :items="primaryClientOptions"
                      placeholder="Select a client"
                      class="w-full"
                    />
                  </UFormField>
                  <UFormField
                    label="Sandbox client ID"
                    description="Optional active BFF client from a sandbox project. Linking it enables the temporary demo-account button on this hosted application."
                  >
                    <UInput
                      v-model="hostedApplication.sandboxClientId"
                      placeholder="Optional sandbox client UUID"
                      class="w-full font-mono"
                    />
                  </UFormField>
                </div>
              </section>

              <section class="rounded-xl border border-default">
                <div class="border-b border-default px-5 py-4">
                  <div class="flex items-center gap-2">
                    <UIcon
                      name="i-lucide-route"
                      class="size-4 text-primary"
                    />
                    <h3 class="font-semibold text-highlighted">
                      Redirect policy
                    </h3>
                  </div>
                  <p class="mt-1 text-sm text-muted">
                    Identity redirects only to these approved product endpoints after authentication.
                  </p>
                </div>
                <div class="grid gap-4 p-5">
                  <UFormField
                    label="Application URL"
                    required
                  >
                    <UInput
                      v-model="hostedApplication.applicationUrl"
                      type="url"
                      placeholder="https://app.example.com/dashboard"
                      class="w-full font-mono"
                    />
                  </UFormField>
                  <UFormField
                    label="Callback URL"
                    required
                  >
                    <UInput
                      v-model="hostedApplication.callbackUrl"
                      type="url"
                      placeholder="https://app.example.com/api/auth/callback"
                      class="w-full font-mono"
                    />
                  </UFormField>
                </div>
              </section>

              <section
                v-if="hostedApplication.id"
                class="rounded-xl border border-default"
              >
                <div class="flex items-center justify-between gap-4 px-5 py-4">
                  <div>
                    <h3 class="font-semibold text-highlighted">
                      Availability
                    </h3>
                    <p class="mt-1 text-sm text-muted">
                      Disabled applications cannot begin a new hosted authentication flow.
                    </p>
                  </div>
                  <USelect
                    v-model="hostedApplication.status"
                    :items="[{ label: 'Active', value: 'active' }, { label: 'Disabled', value: 'disabled' }]"
                    class="w-32"
                  />
                </div>
              </section>
            </div>

            <div class="sticky bottom-0 -mx-1 flex justify-end gap-2 border-t border-default bg-default/95 px-1 py-4 backdrop-blur">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { hostedApplicationModalOpen = false }"
              />
              <UButton
                type="submit"
                :label="hostedApplication.id ? 'Save application' : 'Create application'"
                icon="i-lucide-save"
              />
            </div>
          </form>
        </template>
      </USlideover>
      <UModal
        v-model:open="invitationModalOpen"
        title="Invite a member"
        description="Create a one-time invitation for this project."
      >
        <template #body>
          <form
            class="space-y-4"
            @submit.prevent="sendInvitation"
          >
            <UFormField
              label="Email"
              required
            >
              <UInput
                v-model="invitation.email"
                autofocus
                type="email"
                class="w-full"
              />
            </UFormField><UCheckbox
              v-model="invitation.is_admin"
              label="Project administrator"
            /><div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { invitationModalOpen = false }"
              /><UButton
                type="submit"
                label="Create invitation"
                icon="i-lucide-mail-plus"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        v-model:open="roleModalOpen"
        title="Create project role"
        description="Roles collect stable permission keys."
      >
        <template #body>
          <form
            class="space-y-4"
            @submit.prevent="addRole"
          >
            <UFormField
              label="Name"
              required
            >
              <UInput
                v-model="role.name"
                autofocus
                placeholder="Editor"
                class="w-full"
              />
            </UFormField><UFormField
              label="Slug"
              required
            >
              <UInput
                v-model="role.slug"
                placeholder="editor"
                class="w-full"
              />
            </UFormField><UFormField label="Description">
              <UTextarea
                v-model="role.description"
                class="w-full"
              />
            </UFormField><div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { roleModalOpen = false }"
              /><UButton
                type="submit"
                label="Create role"
                icon="i-lucide-plus"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        v-model:open="permissionModalOpen"
        title="Create permission"
        description="Use a stable namespaced key shared with consuming services."
      >
        <template #body>
          <form
            class="space-y-4"
            @submit.prevent="addPermission"
          >
            <UFormField
              label="Permission key"
              required
            >
              <UInput
                v-model="permissionForm.key"
                autofocus
                placeholder="documents.read"
                class="w-full"
              />
            </UFormField><UFormField label="Name">
              <UInput
                v-model="permissionForm.name"
                placeholder="Read documents"
                class="w-full"
              />
            </UFormField><UFormField label="Description">
              <UTextarea
                v-model="permissionForm.description"
                class="w-full"
              />
            </UFormField><div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { permissionModalOpen = false }"
              /><UButton
                type="submit"
                label="Create permission"
                icon="i-lucide-plus"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        v-model:open="webhookModalOpen"
        title="Add cleanup webhook"
        description="Identity signs every lifecycle event delivered to this endpoint."
      >
        <template #body>
          <form
            class="space-y-4"
            @submit.prevent="addWebhook"
          >
            <UFormField
              label="Endpoint URL"
              required
            >
              <UInput
                v-model="webhookForm.url"
                autofocus
                type="url"
                placeholder="https://api.example.com/api/webhooks/identity"
                class="w-full"
              />
            </UFormField><UFormField label="Events">
              <UCheckboxGroup
                v-model="webhookForm.events"
                :items="[{ label: 'Temporary user expired', value: 'identity.user.expired' }, { label: 'User deletion requested', value: 'identity.user.deletion_requested' }]"
              />
            </UFormField><div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { webhookModalOpen = false }"
              /><UButton
                type="submit"
                label="Add webhook"
                icon="i-lucide-plus"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        v-model:open="deletionModalOpen"
        title="Schedule project deletion"
        :description="project ? `Type ${project.slug} to confirm. Access is revoked immediately and deletion remains reversible until the configured deadline.` : ''"
      >
        <template #body>
          <form
            v-if="project"
            class="space-y-5"
            @submit.prevent="scheduleDeletion"
          >
            <UFormField
              label="Project slug"
              :description="`Type ${project.slug} exactly to continue.`"
            >
              <UInput
                v-model="deletionConfirmation"
                autofocus
                class="w-full"
              />
            </UFormField>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { deletionModalOpen = false }"
              />
              <UButton
                type="submit"
                label="Schedule deletion"
                color="error"
                icon="i-lucide-trash-2"
                :disabled="deletionConfirmation !== project.slug"
                :loading="deletionSaving"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        v-model:open="suspensionModalOpen"
        title="Suspend project"
        :description="project ? `Type ${project.slug} to confirm. This revokes every active project session immediately; reactivation requires users to sign in again.` : ''"
      >
        <template #body>
          <form
            v-if="project"
            class="space-y-5"
            @submit.prevent="suspendProject"
          >
            <UFormField
              label="Project slug"
              :description="`Type ${project.slug} exactly to continue.`"
            >
              <UInput
                v-model="suspensionConfirmation"
                autofocus
                class="w-full"
              />
            </UFormField>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { suspensionModalOpen = false }"
              />
              <UButton
                type="submit"
                label="Suspend project"
                color="warning"
                icon="i-lucide-pause-circle"
                :disabled="suspensionConfirmation !== project.slug"
                :loading="suspensionSaving"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        :open="selectedAccessDeletion != null"
        :title="selectedAccessDeletion?.kind === 'role' ? 'Delete project role' : 'Delete project permission'"
        :description="selectedAccessDeletion ? `Type ${accessDeletionExpectedConfirmation} exactly to permanently delete this ${selectedAccessDeletion.kind}.` : ''"
        @update:open="value => { if (!value) selectedAccessDeletion = null }"
      >
        <template #body>
          <form
            v-if="selectedAccessDeletion"
            class="space-y-5"
            @submit.prevent="deleteSelectedAccessResource"
          >
            <UAlert
              v-if="accessDeletionBlocked"
              color="warning"
              variant="soft"
              icon="i-lucide-link-2"
              title="Resolve dependencies before deleting"
              description="Access assignments are never removed automatically. Reassign or remove the items below, then retry."
            />
            <UAlert
              v-else
              color="error"
              variant="soft"
              icon="i-lucide-triangle-alert"
              title="This cannot be undone"
              :description="selectedAccessDeletion.kind === 'role' ? 'The role and its permission composition will be permanently removed.' : 'The permission will be permanently removed from this project. Reusable catalog entries are not affected.'"
            />
            <div
              v-if="accessDeletionBlocked"
              class="space-y-3 rounded-xl border border-default p-4 text-sm"
            >
              <div v-if="accessDeletionMemberships.length">
                <p class="font-medium text-highlighted">
                  Assigned memberships
                </p>
                <ul class="mt-1 list-disc space-y-1 pl-5 text-muted">
                  <li
                    v-for="membership in accessDeletionMemberships"
                    :key="membership.id"
                  >
                    {{ membership.user.username || membership.user.email || membership.user.id }} ({{ membership.status }})
                  </li>
                </ul>
                <UButton
                  class="mt-2"
                  label="Manage members"
                  size="xs"
                  color="neutral"
                  variant="soft"
                  @click="() => { selectedAccessDeletion = null; activeTab = 'members' }"
                />
              </div>
              <div v-if="accessDeletionRoles.length">
                <p class="font-medium text-highlighted">
                  Granted to roles
                </p>
                <p class="mt-1 text-muted">
                  {{ accessDeletionRoles.map(role => `${role.name} (${role.slug})`).join(', ') }}
                </p>
              </div>
              <div v-if="accessDeletionIsRegistrationDefault">
                <p class="font-medium text-highlighted">
                  Public registration default
                </p>
                <p class="mt-1 text-muted">
                  Select another default role or disable the default role in the registration policy.
                </p>
              </div>
              <div v-if="accessDeletionManifestClient">
                <p class="font-medium text-highlighted">
                  Managed by {{ accessDeletionManifestClient.name }}
                </p>
                <p class="mt-1 text-muted">
                  Remove this key from the client permission manifest first. It can be deleted after the next synchronization marks it stale.
                </p>
              </div>
            </div>
            <UAlert
              v-if="accessDeletionServerMessage"
              color="warning"
              variant="soft"
              title="Dependencies changed"
              :description="accessDeletionServerMessage"
            />
            <UFormField
              :label="selectedAccessDeletion.kind === 'role' ? 'Role slug' : 'Permission key'"
              :description="`Type ${accessDeletionExpectedConfirmation} exactly to continue.`"
            >
              <UInput
                v-model="accessDeletionConfirmation"
                autofocus
                class="w-full"
              />
            </UFormField>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                @click="() => { selectedAccessDeletion = null }"
              />
              <UButton
                type="submit"
                :label="selectedAccessDeletion.kind === 'role' ? 'Delete role' : 'Delete permission'"
                color="error"
                icon="i-lucide-trash-2"
                :disabled="accessDeletionBlocked || accessDeletionConfirmation !== accessDeletionExpectedConfirmation"
                :loading="accessDeletionSaving"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        :open="selectedRole != null"
        title="Role permissions"
        :description="selectedRole ? `Configure ${selectedRole.name}` : ''"
        @update:open="value => { if (!value) selectedRole = null }"
      >
        <template #body>
          <form
            v-if="selectedRole"
            class="space-y-5"
            @submit.prevent="saveRolePermissions"
          >
            <div class="max-h-96 overflow-y-auto rounded-xl border border-default p-3">
              <UCheckboxGroup
                v-model="selectedRole.permissionIds"
                :items="rolePermissionChoices"
                class="space-y-2"
              >
                <template #label="{ item: permission }">
                  <span class="flex items-center justify-between gap-3">
                    <span>{{ permission.label }}</span>
                    <UBadge
                      v-if="permission.global"
                      color="primary"
                      variant="soft"
                    >
                      Catalog
                    </UBadge>
                  </span>
                </template>
              </UCheckboxGroup>
              <p
                v-if="rolePermissionChoices.length === 0"
                class="py-5 text-center text-sm text-muted"
              >
                No permissions are available yet.
              </p>
            </div><div class="flex justify-end">
              <UButton
                type="submit"
                label="Save role"
                icon="i-lucide-save"
              />
            </div>
          </form>
        </template>
      </UModal>
      <UModal
        :open="selectedMembership != null"
        title="Membership access"
        :description="selectedMembership?.label"
        @update:open="value => { if (!value) selectedMembership = null }"
      >
        <template #body>
          <form
            v-if="selectedMembership"
            class="space-y-6"
            @submit.prevent="saveMembership"
          >
            <UCheckbox
              v-model="selectedMembership.isAdmin"
              label="Project administrator"
            /><UFormField label="Status">
              <USelect
                v-model="selectedMembership.status"
                :items="[{ label: 'Active', value: 'active' }, { label: 'Suspended', value: 'suspended' }]"
                class="w-full"
              />
            </UFormField><div>
              <p class="mb-3 text-sm font-medium">
                Roles
              </p><UCheckboxGroup
                v-model="selectedMembership.roleIds"
                :items="projectRoleOptions"
                class="grid gap-3 sm:grid-cols-2"
              />
            </div><div>
              <p class="mb-3 text-sm font-medium">
                Direct permission grants
              </p><UCheckboxGroup
                v-model="selectedMembership.permissionIds"
                :items="projectPermissionOptions"
                class="grid gap-3 sm:grid-cols-2"
              />
            </div><div class="flex justify-between gap-3">
              <UButton
                type="button"
                label="Remove membership"
                color="error"
                variant="outline"
                icon="i-lucide-user-minus"
                @click="removeSelectedMembership"
              /><UButton
                type="submit"
                label="Save access"
                icon="i-lucide-save"
              />
            </div>
          </form>
        </template>
      </UModal>
    </template>
  </IdentityPanel>
</template>

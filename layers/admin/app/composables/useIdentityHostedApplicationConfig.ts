import type {
  IdentityClient,
  IdentityHostedApplication,
  IdentityProjectDetails
} from '../../types/identity-access'

export type IdentityHostedApplicationConfigSection
  = | 'general'
    | 'security'
    | 'clients'
    | 'redirects'
    | 'availability'

export type HostedApplicationConfigForm = {
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
  status: IdentityHostedApplication['status']
}

export const identityHostedApplicationConfigSections: Array<{
  value: IdentityHostedApplicationConfigSection
  label: string
  description: string
  icon: string
}> = [
  { value: 'general', label: 'General', description: 'Application identity, environment, and connection summary.', icon: 'i-lucide-app-window' },
  { value: 'security', label: 'Security', description: 'Sign-in providers and account policies.', icon: 'i-lucide-shield-check' },
  { value: 'clients', label: 'Client binding', description: 'Confidential clients used by this application.', icon: 'i-lucide-key-round' },
  { value: 'redirects', label: 'Redirect policy', description: 'Approved product and callback endpoints.', icon: 'i-lucide-route' },
  { value: 'availability', label: 'Availability', description: 'Control whether hosted auth can start.', icon: 'i-lucide-activity' }
]

export function createHostedApplicationConfigForm(): HostedApplicationConfigForm {
  return {
    name: '',
    key: '',
    primaryClientId: '',
    sandboxClientId: '',
    applicationUrl: '',
    callbackUrl: '',
    policyRevision: '',
    googleEnabled: false,
    demoOnly: false,
    termsRequired: false,
    termsUrl: '',
    privacyUrl: '',
    status: 'active'
  }
}

function formFromApplication(application: IdentityHostedApplication): HostedApplicationConfigForm {
  return {
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
}

export async function useIdentityHostedApplicationConfig(
  projectId: MaybeRefOrGetter<string>,
  applicationId: MaybeRefOrGetter<string>
) {
  const access = useIdentityAccess()
  const { data: project, status, error, refresh } = await access.project(projectId)
  const form = reactive(createHostedApplicationConfigForm())

  const application = computed(() => {
    const currentProject = project.value as IdentityProjectDetails | null | undefined
    return currentProject?.hosted_applications.find(item => item.id === toValue(applicationId))
  })
  const clients = computed<IdentityClient[]>(() => project.value?.clients ?? [])
  const primaryClientOptions = computed(() => clients.value
    .filter(client => client.status === 'active')
    .map(client => ({ label: client.name, value: client.id })))

  watch(application, (currentApplication) => {
    if (currentApplication) Object.assign(form, formFromApplication(currentApplication))
  }, { immediate: true })

  watch(() => form.sandboxClientId, (sandboxClientId) => {
    if (!sandboxClientId) form.demoOnly = false
  })

  async function save() {
    const currentApplication = application.value
    if (!currentApplication) return

    await access.updateHostedApplication(toValue(projectId), currentApplication.id, {
      name: form.name,
      primary_client_id: form.primaryClientId,
      sandbox_client_id: form.sandboxClientId || null,
      application_url: form.applicationUrl,
      callback_url: form.callbackUrl,
      authentication_mode: 'hosted',
      policy_revision: form.policyRevision || null,
      status: form.status,
      authentication: {
        google_enabled: form.googleEnabled,
        demo_only: form.demoOnly && Boolean(form.sandboxClientId),
        terms_required: form.termsRequired,
        terms_url: form.termsRequired ? form.termsUrl.trim() || null : null,
        privacy_url: form.privacyUrl.trim() || null
      }
    })

    await refresh()
  }

  return {
    project,
    application,
    clients,
    primaryClientOptions,
    form,
    status,
    error,
    refresh,
    save
  }
}

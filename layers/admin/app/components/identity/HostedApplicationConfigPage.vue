<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui'
import {
  identityHostedApplicationConfigSections,
  type IdentityHostedApplicationConfigSection,
  useIdentityHostedApplicationConfig
} from '#admin/app/composables/useIdentityHostedApplicationConfig'

const props = defineProps<{
  projectId: string
  applicationId: string
  section: IdentityHostedApplicationConfigSection
}>()

const localePath = useLocalePath()
const toast = useToast()
const saving = ref(false)
const access = useIdentityAccess()
const {
  project,
  application,
  primaryClientOptions,
  form,
  status,
  error,
  refresh,
  save
} = await useIdentityHostedApplicationConfig(
  toRef(props, 'projectId'),
  toRef(props, 'applicationId')
)
const { data: availableProjects, status: availableProjectsStatus } = await access.projects()
const selectedProjectId = useIdentitySelectedProject()

const projectTabPath = (tab: string) => localePath(`/admin/projects/${props.projectId}?tab=${tab}`)
const projectNavigationBeforeApplications = computed<NavigationMenuItem[]>(() => [
  { label: 'Overview', icon: 'i-lucide-layout-dashboard', to: projectTabPath('overview') },
  { label: 'Members', icon: 'i-lucide-users', badge: project.value?.memberships.length ?? 0, to: projectTabPath('members') },
  { label: 'Roles & permissions', icon: 'i-lucide-shield-check', to: projectTabPath('access') },
  { label: 'Clients', icon: 'i-lucide-server-cog', badge: project.value?.clients.length ?? 0, to: projectTabPath('clients') }
])
const projectNavigationAfterApplications = computed<NavigationMenuItem[]>(() => [
  { label: 'Webhooks', icon: 'i-lucide-webhook', badge: project.value?.webhooks.length ?? 0, to: projectTabPath('webhooks') },
  { label: 'Audit', icon: 'i-lucide-scroll-text', to: projectTabPath('audit') },
  { label: 'Settings', icon: 'i-lucide-settings-2', to: projectTabPath('settings') }
])
const applicationSwitcherItems = computed(() => (project.value?.hosted_applications ?? []).map(item => ({
  label: item.name,
  value: item.id
})))
const projectSwitcherItems = computed(() => {
  const projects = [...(availableProjects.value ?? [])]
  const currentProject = project.value
  if (currentProject && !projects.some(item => item.id === currentProject.id)) {
    projects.push(currentProject)
  }

  return projects.map(item => ({ label: item.name, value: item.id }))
})
const categoryNavigation = computed<NavigationMenuItem[]>(() => identityHostedApplicationConfigSections.map(item => ({
  label: item.label,
  icon: item.icon,
  to: localePath(`/admin/application-config/${props.projectId}/${props.applicationId}/${item.value}`),
  active: item.value === props.section
})))
const mobileCategoryTabs = computed(() => identityHostedApplicationConfigSections.map(item => ({
  label: item.label,
  icon: item.icon,
  value: item.value
})))
const projectOverviewPath = computed(() => localePath(`/admin/projects/${props.projectId}?tab=overview`))
const applicationsPath = computed(() => localePath(`/admin/projects/${props.projectId}?tab=applications`))
const applicationPublicUrl = computed(() => {
  if (!application.value) return ''
  try {
    const url = new URL(application.value.application_url)
    return url.origin
  } catch {
    return application.value.application_url
  }
})
const primaryClientBindingLabel = computed(() => {
  const currentApplication = application.value
  if (!currentApplication) return 'Not connected'

  return primaryClientOptions.value.find(item => item.value === currentApplication.primary_client_id)?.label ?? 'Not connected'
})

async function switchApplication(applicationId: string | undefined) {
  if (!applicationId || applicationId === props.applicationId) return

  await navigateTo(localePath(`/admin/application-config/${props.projectId}/${applicationId}/${props.section}`))
}

watch(project, (currentProject) => {
  if (currentProject) selectedProjectId.value = currentProject.id
}, { immediate: true })

async function switchProject(nextProjectId: string | undefined) {
  if (!nextProjectId || nextProjectId === props.projectId) return

  selectedProjectId.value = nextProjectId
  await navigateTo(localePath(`/admin/projects/${nextProjectId}?tab=applications`))
}

async function submit() {
  saving.value = true
  try {
    await save()
    toast.add({ title: 'Application configuration saved', color: 'success' })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <IdentityPanel
    panel-id="identity-hosted-application-config"
    :title="application?.name || 'Application configuration'"
    icon="i-lucide-panels-top-left"
    :back-to="applicationsPath"
    back-label="Applications"
    body-class="mx-auto flex w-full max-w-[1600px] flex-col gap-5"
  >
    <template #title>
      <USelect
        :model-value="project?.id ?? props.projectId"
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
      title="Loading application configuration"
    />
    <IdentityShellState
      v-else-if="error || !project || !application"
      state="error"
      title="Unable to load application"
      :description="error?.statusMessage || 'The selected application is not connected to this project.'"
      @retry="refresh()"
    />
    <div
      v-else
      class="grid min-w-0 gap-5 xl:grid-cols-[14rem_minmax(0,1fr)]"
    >
      <aside
        class="hidden xl:block"
        aria-label="Project navigation"
      >
        <div class="sticky top-4 space-y-3">
          <NuxtLink
            :to="projectOverviewPath"
            class="group block rounded-lg px-3 py-2 transition-colors hover:bg-elevated/60 focus-visible:outline-2 focus-visible:outline-primary"
            :aria-label="`Back to ${project?.name || 'project'} overview`"
          >
            <span class="flex items-center gap-2 text-sm font-medium text-highlighted">
              <UIcon
                name="i-lucide-arrow-left"
                class="size-4 shrink-0 text-muted transition-transform group-hover:-translate-x-0.5"
              />
              <span class="truncate">{{ project?.name || 'Project' }}</span>
            </span>
            <span class="mt-1 block pl-6 text-xs text-muted">Back to project overview</span>
          </NuxtLink>

          <UNavigationMenu
            :items="projectNavigationBeforeApplications"
            orientation="vertical"
            color="neutral"
            variant="pill"
            :ui="{
              link: 'min-h-10 gap-2.5 px-3',
              linkLeadingIcon: 'size-4.5'
            }"
          />

          <div class="space-y-1">
            <NuxtLink
              :to="applicationsPath"
              class="flex min-h-10 items-center gap-2.5 rounded-md bg-elevated px-3 py-2 text-sm font-medium text-highlighted focus-visible:outline-2 focus-visible:outline-primary"
            >
              <UIcon
                name="i-lucide-panels-top-left"
                class="size-4.5 shrink-0 text-primary"
              />
              <span class="truncate">Applications</span>
              <span class="ml-auto rounded border border-default px-1.5 py-0.5 text-[11px] tabular-nums text-muted">
                {{ project?.hosted_applications.length ?? 0 }}
              </span>
            </NuxtLink>

            <div class="ml-4 border-l border-default/80 pl-2">
              <UNavigationMenu
                :items="categoryNavigation"
                orientation="vertical"
                color="neutral"
                variant="pill"
                :ui="{
                  link: 'min-h-9 gap-2 px-3 text-sm',
                  linkLeadingIcon: 'size-4'
                }"
              />
            </div>
          </div>

          <UNavigationMenu
            :items="projectNavigationAfterApplications"
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

      <main class="min-w-0 space-y-5">
        <div class="min-w-0 xl:hidden">
          <USelect
            :model-value="props.section"
            :items="mobileCategoryTabs"
            value-key="value"
            aria-label="Application configuration section"
            class="w-full md:hidden"
            @update:model-value="value => navigateTo(localePath(`/admin/application-config/${props.projectId}/${props.applicationId}/${value}`))"
          />
          <UTabs
            :items="mobileCategoryTabs"
            :model-value="props.section"
            variant="pill"
            :content="false"
            size="lg"
            class="hidden min-w-0 md:block"
            @update:model-value="value => navigateTo(localePath(`/admin/application-config/${props.projectId}/${props.applicationId}/${value}`))"
          />
        </div>

        <section class="min-w-0">
          <div class="mb-4 flex flex-wrap items-center justify-between gap-4 rounded-lg border border-default bg-elevated/30 px-4 py-3">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-4 gap-y-2">
              <div class="flex min-w-48 items-center gap-3">
                <UIcon
                  name="i-lucide-panels-top-left"
                  class="size-5 shrink-0 text-primary"
                />
                <div class="min-w-0">
                  <p class="text-xs font-medium uppercase tracking-wide text-muted">
                    Application
                  </p>
                  <USelect
                    :model-value="props.applicationId"
                    :items="applicationSwitcherItems"
                    aria-label="Select application"
                    size="sm"
                    class="mt-0.5 min-w-48 max-w-64"
                    @update:model-value="switchApplication"
                  />
                </div>
              </div>
              <div class="min-w-0 text-sm">
                <p class="truncate font-mono text-highlighted">
                  {{ form.key }}
                </p>
                <p class="mt-0.5 truncate text-xs text-muted">
                  {{ project.mode }} environment · {{ applicationPublicUrl }}
                </p>
              </div>
            </div>
            <UBadge
              :color="form.status === 'active' ? 'success' : 'neutral'"
              variant="soft"
              class="capitalize"
            >
              {{ form.status }}
            </UBadge>
          </div>

          <form
            class="space-y-5"
            @submit.prevent="submit"
          >
            <template v-if="props.section === 'general'">
              <div class="grid gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(18rem,0.75fr)]">
                <div class="rounded-lg border border-default bg-default/40 p-4 sm:p-5">
                  <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                      <h2 class="font-medium text-highlighted">
                        Application identity
                      </h2>
                      <p class="mt-1 text-sm text-muted">
                        The name and stable key used by connected services.
                      </p>
                    </div>
                    <UIcon
                      name="i-lucide-panels-top-left"
                      class="size-5 shrink-0 text-primary"
                    />
                  </div>
                  <div class="grid gap-4 sm:grid-cols-2">
                    <UFormField
                      label="Application name"
                      required
                    >
                      <UInput
                        v-model="form.name"
                        class="w-full"
                      />
                    </UFormField>
                    <UFormField
                      label="Application key"
                      description="Stable key used by the consuming BFF."
                    >
                      <UInput
                        v-model="form.key"
                        disabled
                        class="w-full font-mono"
                      />
                    </UFormField>
                  </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                  <div class="rounded-lg border border-default bg-default/40 px-4 py-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted">
                      Project
                    </p>
                    <p class="mt-1 font-medium text-highlighted">
                      {{ project.name }}
                    </p>
                    <p class="mt-1 text-sm text-muted">
                      {{ project.mode }} environment · {{ project.registration_mode === 'public' ? 'Public signup' : 'Invite only' }}
                    </p>
                  </div>
                  <div class="rounded-lg border border-default bg-default/40 px-4 py-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted">
                      Connection
                    </p>
                    <p
                      class="mt-1 truncate font-mono text-sm text-highlighted"
                      :title="application.application_url"
                    >
                      {{ applicationPublicUrl }}
                    </p>
                    <p
                      class="mt-1 truncate text-xs text-muted"
                      :title="application.callback_url"
                    >
                      {{ application.callback_url }}
                    </p>
                  </div>
                  <div class="rounded-lg border border-default bg-default/40 px-4 py-3 sm:col-span-2 xl:col-span-1">
                    <div class="flex items-center justify-between gap-3">
                      <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-muted">
                          Client binding
                        </p>
                        <p class="mt-1 truncate font-medium text-highlighted">
                          {{ primaryClientBindingLabel }}
                        </p>
                      </div>
                      <UBadge
                        :color="application.sandbox_client_id ? 'info' : 'neutral'"
                        variant="soft"
                      >
                        {{ application.sandbox_client_id ? 'Primary + sandbox' : 'Primary only' }}
                      </UBadge>
                    </div>
                  </div>
                </div>
              </div>
            </template>

            <template v-else-if="props.section === 'security'">
              <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-default bg-default/40 p-4">
                  <UCheckbox
                    v-model="form.googleEnabled"
                    label="Offer Google sign-in"
                    description="Requires provider credentials and an approved callback on the Identity API."
                  />
                </div>
                <div class="rounded-lg border border-default bg-default/40 p-4">
                  <UCheckbox
                    v-model="form.termsRequired"
                    label="Require terms acceptance"
                    description="Record acceptance when a user creates an account."
                  />
                </div>
              </div>
              <div class="grid gap-4 lg:grid-cols-3">
                <UFormField
                  label="Approved policy revision"
                  :required="form.termsRequired"
                  class="lg:col-span-1"
                >
                  <UInput
                    v-model="form.policyRevision"
                    placeholder="2026-10-08"
                    class="w-full"
                  />
                </UFormField>
                <UFormField
                  label="Terms of Service URL"
                  :required="form.termsRequired"
                  class="lg:col-span-1"
                >
                  <UInput
                    v-model="form.termsUrl"
                    type="url"
                    class="w-full font-mono"
                  />
                </UFormField>
                <UFormField
                  label="Privacy Policy URL"
                  class="lg:col-span-1"
                >
                  <UInput
                    v-model="form.privacyUrl"
                    type="url"
                    class="w-full font-mono"
                  />
                </UFormField>
              </div>
            </template>

            <template v-else-if="props.section === 'clients'">
              <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-default bg-default/40 p-4">
                  <UFormField
                    label="Primary client"
                    description="An active client from this project."
                    required
                  >
                    <USelect
                      v-model="form.primaryClientId"
                      :items="primaryClientOptions"
                      placeholder="Select a client"
                      class="w-full"
                    />
                  </UFormField>
                </div>
                <div class="rounded-lg border border-default bg-default/40 p-4">
                  <UFormField
                    label="Sandbox client ID"
                    description="Optional active BFF client from a sandbox project."
                  >
                    <UInput
                      v-model="form.sandboxClientId"
                      placeholder="Optional sandbox client UUID"
                      class="w-full font-mono"
                    />
                  </UFormField>
                </div>
              </div>
            </template>

            <template v-else-if="props.section === 'redirects'">
              <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-lg border border-default bg-default/40 p-4">
                  <UFormField
                    label="Application URL"
                    description="The product surface users return to after authentication."
                    required
                  >
                    <UInput
                      v-model="form.applicationUrl"
                      type="url"
                      class="w-full font-mono"
                    />
                  </UFormField>
                </div>
                <div class="rounded-lg border border-default bg-default/40 p-4">
                  <UFormField
                    label="Callback URL"
                    description="The approved BFF callback that exchanges the authorization code."
                    required
                  >
                    <UInput
                      v-model="form.callbackUrl"
                      type="url"
                      class="w-full font-mono"
                    />
                  </UFormField>
                </div>
              </div>
            </template>

            <template v-else-if="props.section === 'availability'">
              <div class="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-default bg-default/40 p-4">
                <div>
                  <p class="font-medium text-highlighted">
                    Hosted authentication availability
                  </p>
                  <p class="mt-1 text-sm text-muted">
                    Disabled applications cannot begin a new hosted authentication flow.
                  </p>
                </div>
                <USelect
                  v-model="form.status"
                  :items="[{ label: 'Active', value: 'active' }, { label: 'Disabled', value: 'disabled' }]"
                  class="w-32"
                />
              </div>
            </template>

            <div class="flex flex-wrap items-center justify-end gap-2 rounded-lg border border-default bg-elevated/30 px-4 py-3">
              <UButton
                label="Cancel"
                color="neutral"
                variant="ghost"
                :to="applicationsPath"
              />
              <UButton
                type="submit"
                label="Save changes"
                icon="i-lucide-save"
                :loading="saving"
              />
            </div>
          </form>
        </section>
      </main>
    </div>
  </IdentityPanel>
</template>

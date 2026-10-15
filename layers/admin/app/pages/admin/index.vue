<script setup lang="ts">
import type { IdentityProject } from '#admin/types/identity-access'

definePageMeta({ layout: 'identity-admin', middleware: ['identity-admin'] })

const localePath = useLocalePath()
const access = useIdentityAccess()
const [projectsRequest, sessionRequest] = await Promise.all([
  access.projects(),
  access.session()
])
const { data: projects, status: projectsStatus, error: projectsError, refresh: refreshProjects } = projectsRequest
const { data: session } = sessionRequest
const refreshing = ref(false)
const projectEnvironment = ref<'all' | IdentityProject['mode']>('all')
const clientEnvironment = ref<'all' | IdentityProject['mode']>('all')
const applicationEnvironment = ref<'all' | IdentityProject['mode']>('all')
const {
  projectResourcesStatus,
  clientEntries: clientCards,
  applicationEntries: applicationCards,
  refreshProjectResources
} = await useIdentityProjectResources(projects, 'identity-overview-project-resources')

const visibleProjects = computed(() => {
  const matchingProjects = (projects.value ?? []).filter(project => (
    projectEnvironment.value === 'all' || project.mode === projectEnvironment.value
  ))

  return matchingProjects.slice(0, 6)
})
const filteredProjectCount = computed(() => (projects.value ?? []).filter(project => (
  projectEnvironment.value === 'all' || project.mode === projectEnvironment.value
)).length)
const environmentFilterItems = [
  { label: 'All environments', value: 'all' },
  { label: 'Live', value: 'live' },
  { label: 'Sandbox', value: 'sandbox' }
]
const visibleClientCards = computed(() => clientCards.value.filter(entry => (
  clientEnvironment.value === 'all' || entry.project.mode === clientEnvironment.value
)))
const visibleApplicationCards = computed(() => applicationCards.value.filter(entry => (
  applicationEnvironment.value === 'all' || entry.project.mode === applicationEnvironment.value
)))
const clientCountLabel = computed(() => clientEnvironment.value === 'all'
  ? `${clientCards.value.length} total`
  : `${visibleClientCards.value.length} of ${clientCards.value.length}`)
const applicationCountLabel = computed(() => applicationEnvironment.value === 'all'
  ? `${applicationCards.value.length} total`
  : `${visibleApplicationCards.value.length} of ${applicationCards.value.length}`)

const quickLinks = computed(() => [
  {
    title: 'Project access',
    description: 'Manage memberships, roles, permissions, clients, and webhooks.',
    icon: 'i-lucide-shield-check',
    to: localePath('/admin/projects'),
    label: 'Open projects'
  },
  ...(session.value?.isSystemAdmin
    ? [
        {
          title: 'Identity directory',
          description: 'Review global accounts, verification, and installation access.',
          icon: 'i-lucide-users-round',
          to: localePath('/admin/identity-users'),
          label: 'Review users'
        },
        {
          title: 'Access catalog',
          description: 'Publish reusable roles and permissions across projects.',
          icon: 'i-lucide-library',
          to: localePath('/admin/access-catalog'),
          label: 'Open catalog'
        }
      ]
    : [])
])

function projectStatusLabel(status: IdentityProject['status']) {
  return status === 'pending_deletion' ? 'Pending deletion' : status === 'active' ? 'Active' : 'Suspended'
}

async function refreshOverview() {
  refreshing.value = true
  try {
    await Promise.all([
      refreshProjects(),
      refreshProjectResources()
    ])
  } finally {
    refreshing.value = false
  }
}
</script>

<template>
  <IdentityPanel
    panel-id="identity-overview"
    title="Overview"
    icon="i-lucide-house"
    container-class="max-w-[1600px]"
    :body-class="'mx-auto flex w-full max-w-[1600px] flex-col gap-5'"
  >
    <template #actions>
      <UButton
        label="Refresh overview"
        icon="i-lucide-refresh-cw"
        color="neutral"
        variant="outline"
        :loading="refreshing"
        @click="refreshOverview"
      />
      <UButton
        label="Open project directory"
        icon="i-lucide-arrow-up-right"
        :to="localePath('/admin/projects')"
      />
    </template>

    <IdentityShellState
      v-if="projectsStatus === 'pending'"
      state="loading"
      title="Loading identity overview"
    />
    <IdentityShellState
      v-else-if="projectsError"
      state="error"
      title="Unable to load the overview"
      :description="projectsError.statusMessage || 'The identity service did not return the project list.'"
      @retry="refreshOverview"
    />

    <template v-else>
      <IdentityOverviewSection
        heading-id="projects-heading"
        title="Projects"
        description="Manage identity boundaries and review their connected resources."
      >
        <template #actions>
          <USelect
            v-model="projectEnvironment"
            aria-label="Filter projects by environment"
            :items="[
              { label: 'All environments', value: 'all' },
              { label: 'Live', value: 'live' },
              { label: 'Sandbox', value: 'sandbox' }
            ]"
            size="sm"
            class="w-40"
          />
          <span class="px-1 text-xs tabular-nums text-muted">{{ visibleProjects.length }} of {{ filteredProjectCount }}</span>
          <UButton
            label="View all"
            color="neutral"
            variant="ghost"
            trailing-icon="i-lucide-arrow-right"
            :to="localePath('/admin/projects')"
          />
        </template>

        <div
          v-if="visibleProjects.length"
          class="grid items-stretch gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
          <IdentityOverviewCard
            v-for="project in visibleProjects"
            :key="project.id"
            icon="i-lucide-folder-key"
          >
            <template #header>
              <IdentityOverviewCardHeader
                :title="project.name"
                :title-to="localePath(`/admin/projects/${project.id}`)"
                :subtitle="project.description || project.slug"
                :manage-to="localePath(`/admin/projects/${project.id}`)"
              />
            </template>
            <template #meta>
              <IdentityOverviewCardMeta layout="grid">
                <IdentityOverviewCardField label="Environment">
                  <UBadge
                    :color="project.mode === 'live' ? 'success' : 'warning'"
                    variant="soft"
                    size="xs"
                    class="capitalize"
                  >
                    {{ project.mode }}
                  </UBadge>
                </IdentityOverviewCardField>
                <IdentityOverviewCardField label="Registration">
                  <UBadge
                    color="neutral"
                    variant="outline"
                    size="xs"
                  >
                    {{ project.registration_mode === 'public' ? 'Public signup' : 'Invite only' }}
                  </UBadge>
                </IdentityOverviewCardField>
                <IdentityOverviewCardField label="Status">
                  <span class="inline-flex items-center gap-1.5">
                    <span
                      class="size-1.5 rounded-full"
                      :class="project.status === 'active' ? 'bg-success' : project.status === 'suspended' ? 'bg-warning' : 'bg-error'"
                    />
                    {{ projectStatusLabel(project.status) }}
                  </span>
                </IdentityOverviewCardField>
              </IdentityOverviewCardMeta>
            </template>
          </IdentityOverviewCard>
        </div>
        <div
          v-else-if="(projects?.length ?? 0) === 0"
          class="flex flex-col items-center rounded-xl border border-dashed border-default bg-elevated/30 px-6 py-12 text-center"
        >
          <span class="flex size-12 items-center justify-center rounded-2xl bg-elevated text-muted">
            <UIcon
              name="i-lucide-folder-plus"
              class="size-6"
            />
          </span>
          <h3 class="mt-4 font-semibold text-highlighted">
            Create your first project
          </h3>
          <p class="mt-1 max-w-sm text-sm text-muted">
            Projects keep application clients, memberships, and permissions in separate boundaries.
          </p>
          <UButton
            class="mt-4"
            label="Open project directory"
            icon="i-lucide-arrow-up-right"
            :to="localePath('/admin/projects')"
          />
        </div>
        <div
          v-else
          class="flex flex-col items-center rounded-xl border border-dashed border-default bg-elevated/30 px-6 py-12 text-center"
        >
          <span class="flex size-12 items-center justify-center rounded-2xl bg-elevated text-muted">
            <UIcon
              name="i-lucide-filter-x"
              class="size-6"
            />
          </span>
          <h3 class="mt-4 font-semibold text-highlighted">
            No {{ projectEnvironment }} projects
          </h3>
          <p class="mt-1 max-w-sm text-sm text-muted">
            This installation has projects, but none match the selected environment.
          </p>
          <UButton
            class="mt-4"
            label="Show all environments"
            icon="i-lucide-list-filter"
            color="neutral"
            variant="outline"
            @click="projectEnvironment = 'all'"
          />
        </div>
      </IdentityOverviewSection>

      <IdentityOverviewSection
        heading-id="clients-heading"
        title="Clients"
        description="Application credentials across your identity projects."
      >
        <template #actions>
          <USelect
            v-model="clientEnvironment"
            aria-label="Filter clients by environment"
            :items="environmentFilterItems"
            size="sm"
            class="w-44"
          />
          <span class="px-1 text-xs tabular-nums text-muted">{{ clientCountLabel }}</span>
          <UButton
            label="View all"
            color="secondary"
            variant="ghost"
            trailing-icon="i-lucide-arrow-right"
            :to="localePath('/admin/clients')"
          />
        </template>

        <div
          v-if="projectResourcesStatus === 'pending'"
          class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
          <USkeleton
            v-for="item in 3"
            :key="item"
            class="h-44 rounded-xl"
          />
        </div>
        <div
          v-else-if="visibleClientCards.length"
          class="grid items-stretch gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
          <IdentityOverviewCard
            v-for="entry in visibleClientCards"
            :key="entry.client.id"
            icon="i-lucide-key-round"
          >
            <template #header>
              <IdentityOverviewCardHeader
                :title="entry.client.name"
                :subtitle="entry.project.name"
                :subtitle-to="localePath(`/admin/projects/${entry.project.id}`)"
                :manage-to="localePath(`/admin/projects/${entry.project.id}?tab=clients`)"
              />
            </template>
            <template #meta>
              <IdentityOverviewCardMeta layout="grid">
                <IdentityOverviewCardField label="Status">
                  <UBadge
                    :color="entry.client.status === 'active' ? 'success' : 'error'"
                    variant="soft"
                    size="xs"
                  >
                    {{ entry.client.status === 'active' ? 'Active' : 'Disabled' }}
                  </UBadge>
                </IdentityOverviewCardField>
                <IdentityOverviewCardField label="Secret prefix">
                  <code class="block truncate font-mono">{{ entry.client.secret_prefix }}</code>
                </IdentityOverviewCardField>
                <IdentityOverviewCardField label="Applications">
                  <span class="tabular-nums">{{ entry.applications.length }}</span>
                </IdentityOverviewCardField>
              </IdentityOverviewCardMeta>
            </template>
          </IdentityOverviewCard>
        </div>
        <div
          v-else
          class="rounded-xl border border-dashed border-default bg-elevated/30 px-6 py-10 text-center"
        >
          <UIcon
            name="i-lucide-key-round"
            class="size-6 text-muted"
          />
          <p class="mt-3 font-medium text-highlighted">
            {{ clientCards.length ? `No clients in ${clientEnvironment} environment` : 'No clients configured' }}
          </p>
          <p class="mt-1 text-sm text-muted">
            {{ clientCards.length ? 'Choose another environment to review its application credentials.' : 'Create a project client to connect an application.' }}
          </p>
          <UButton
            v-if="clientCards.length"
            class="mt-4"
            label="Show all environments"
            color="neutral"
            variant="outline"
            @click="clientEnvironment = 'all'"
          />
        </div>
      </IdentityOverviewSection>

      <IdentityOverviewSection
        heading-id="applications-heading"
        title="Applications"
        description="Hosted sign-in experiences and connected product surfaces."
      >
        <template #actions>
          <USelect
            v-model="applicationEnvironment"
            aria-label="Filter applications by environment"
            :items="environmentFilterItems"
            size="sm"
            class="w-44"
          />
          <span class="px-1 text-xs tabular-nums text-muted">{{ applicationCountLabel }}</span>
          <UButton
            label="View all"
            color="secondary"
            variant="ghost"
            trailing-icon="i-lucide-arrow-right"
            :to="localePath('/admin/applications')"
          />
        </template>

        <div
          v-if="projectResourcesStatus === 'pending'"
          class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
          <USkeleton
            v-for="item in 3"
            :key="item"
            class="h-56 rounded-xl"
          />
        </div>
        <div
          v-else-if="visibleApplicationCards.length"
          class="grid items-stretch gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
          <IdentityOverviewCard
            v-for="entry in visibleApplicationCards"
            :key="entry.application.id"
            icon="i-lucide-panels-top-left"
          >
            <template #header>
              <IdentityOverviewCardHeader
                :title="entry.application.name"
                :subtitle="entry.project.name"
                :subtitle-to="localePath(`/admin/projects/${entry.project.id}`)"
                :manage-to="localePath(`/admin/projects/${entry.project.id}?tab=applications`)"
              />
            </template>
            <template #meta>
              <IdentityOverviewCardMeta layout="grid">
                <IdentityOverviewCardField label="Status">
                  <UBadge
                    :color="entry.application.status === 'active' ? 'success' : 'error'"
                    variant="soft"
                    size="xs"
                  >
                    {{ entry.application.status === 'active' ? 'Active' : 'Disabled' }}
                  </UBadge>
                </IdentityOverviewCardField>
                <IdentityOverviewCardField label="Environment">
                  <span class="capitalize">{{ entry.project.mode }}</span>
                </IdentityOverviewCardField>
                <IdentityOverviewCardField label="Client">
                  <span
                    class="block truncate"
                    :title="entry.clients.map(client => client.name).join(', ') || 'Not connected'"
                  >{{ entry.clients.map(client => client.name).join(', ') || 'Not connected' }}</span>
                </IdentityOverviewCardField>
              </IdentityOverviewCardMeta>
            </template>

            <div class="mt-3 border-t border-default pt-3">
              <IdentityOverviewCardField label="Base URL">
                <span
                  class="block truncate"
                  :title="entry.application.application_url"
                >
                  {{ entry.application.application_url }}
                </span>
              </IdentityOverviewCardField>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
              <UButton
                label="Site"
                icon="i-lucide-globe"
                color="neutral"
                variant="outline"
                size="xs"
                :to="entry.application.application_url"
                target="_blank"
                rel="noopener noreferrer"
              />
            </div>
          </IdentityOverviewCard>
        </div>
        <div
          v-else
          class="rounded-xl border border-dashed border-default bg-elevated/30 px-6 py-10 text-center"
        >
          <UIcon
            name="i-lucide-panels-top-left"
            class="size-6 text-muted"
          />
          <p class="mt-3 font-medium text-highlighted">
            {{ applicationCards.length ? `No applications in ${applicationEnvironment} environment` : 'No applications configured' }}
          </p>
          <p class="mt-1 text-sm text-muted">
            {{ applicationCards.length ? 'Choose another environment to review its hosted experience.' : 'Connect an application from a project to make it available here.' }}
          </p>
          <UButton
            v-if="applicationCards.length"
            class="mt-4"
            label="Show all environments"
            color="neutral"
            variant="outline"
            @click="applicationEnvironment = 'all'"
          />
        </div>
      </IdentityOverviewSection>

      <section class="space-y-3">
        <div>
          <h2 class="font-semibold text-highlighted">
            Administration
          </h2>
          <p class="mt-1 text-sm text-muted">
            Shortcuts to the tools you use to operate Identity.
          </p>
        </div>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <NuxtLink
            v-for="link in quickLinks"
            :key="link.title"
            :to="link.to"
            class="group flex min-h-24 gap-3 rounded-xl border border-default bg-elevated/40 p-4 transition hover:border-primary/40 hover:bg-elevated/55 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
          >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
              <UIcon
                :name="link.icon"
                class="size-5"
              />
            </span>
            <span class="min-w-0 flex-1">
              <span class="flex items-center justify-between gap-2 font-medium text-highlighted">
                {{ link.title }}
                <UIcon
                  name="i-lucide-arrow-up-right"
                  class="size-4 shrink-0 text-muted transition group-hover:text-primary"
                />
              </span>
              <span class="mt-1 block text-sm leading-5 text-muted">{{ link.description }}</span>
            </span>
          </NuxtLink>
        </div>
      </section>
    </template>
  </IdentityPanel>
</template>

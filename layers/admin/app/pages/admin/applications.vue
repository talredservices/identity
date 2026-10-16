<script setup lang="ts">
import type { IdentityHostedApplication } from '#admin/types/identity-access'

definePageMeta({ layout: 'identity-admin', middleware: ['identity-admin'] })

const localePath = useLocalePath()
const router = useRouter()
const route = useRoute()
const access = useIdentityAccess()
const { data: projects, status: projectsStatus, error: projectsError, refresh: refreshProjects } = await access.projects()
const {
  applicationEntries,
  projectResourcesStatus,
  refreshProjectResources
} = await useIdentityProjectResources(projects, 'identity-applications-directory')

const queryValue = (key: string): string | undefined => {
  const value = route.query[key]
  const candidate = Array.isArray(value) ? value[0] : value
  return typeof candidate === 'string' ? candidate : undefined
}

const environment = ref<'all' | 'live' | 'sandbox'>(
  queryValue('environment') === 'live' || queryValue('environment') === 'sandbox'
    ? queryValue('environment') as 'live' | 'sandbox'
    : 'all'
)
const statusFilter = ref<'all' | IdentityHostedApplication['status']>(
  queryValue('status') === 'active' || queryValue('status') === 'disabled'
    ? queryValue('status') as IdentityHostedApplication['status']
    : 'all'
)
const syncingRoute = ref(false)

const scopedApplications = computed(() => applicationEntries.value.filter((entry) => {
  if (environment.value !== 'all' && entry.project.mode !== environment.value) return false
  if (statusFilter.value !== 'all' && entry.application.status !== statusFilter.value) return false
  return true
}))
const collection = useIdentityCollection(scopedApplications, entry => (
  `${entry.application.name} ${entry.application.key} ${entry.application.application_url} ${entry.project.name} ${entry.project.slug} ${entry.project.mode} ${entry.application.status} ${entry.clients.map(client => client.name).join(' ')}`
), 10, queryValue('q') ?? '')
const routePage = Number(queryValue('page'))
if (Number.isInteger(routePage) && routePage > 0) collection.page.value = routePage

watch([environment, statusFilter, collection.search], () => {
  if (!syncingRoute.value) collection.page.value = 1
})

watch([environment, statusFilter, collection.search, collection.page], async () => {
  if (syncingRoute.value) return
  const query: Record<string, string> = {}
  if (collection.search.value.trim()) query.q = collection.search.value.trim()
  if (environment.value !== 'all') query.environment = environment.value
  if (statusFilter.value !== 'all') query.status = statusFilter.value
  if (collection.page.value > 1) query.page = String(collection.page.value)
  await router.replace({ query })
})

watch(() => route.query, async () => {
  syncingRoute.value = true
  const nextEnvironment = queryValue('environment')
  const nextStatus = queryValue('status')
  environment.value = nextEnvironment === 'live' || nextEnvironment === 'sandbox' ? nextEnvironment : 'all'
  statusFilter.value = nextStatus === 'active' || nextStatus === 'disabled' ? nextStatus : 'all'
  collection.search.value = queryValue('q') ?? ''
  const nextPage = Number(queryValue('page'))
  collection.page.value = Number.isInteger(nextPage) && nextPage > 0 ? nextPage : 1
  await nextTick()
  syncingRoute.value = false
})

async function refresh() {
  await refreshProjects()
  await refreshProjectResources()
}

function applicationConfigPath(application: IdentityHostedApplication): string {
  return localePath(`/admin/application-config/${application.project_id}/${application.id}/general`)
}
</script>

<template>
  <IdentityPanel
    panel-id="identity-applications"
    title="Applications"
    icon="i-lucide-panels-top-left"
    description="Review hosted sign-in experiences and the product surfaces connected to your identity projects."
  >
    <template #actions>
      <UButton
        label="Refresh applications"
        icon="i-lucide-refresh-cw"
        color="neutral"
        variant="outline"
        @click="refresh"
      />
      <UButton
        label="Open projects"
        icon="i-lucide-arrow-up-right"
        :to="localePath('/admin/projects')"
      />
    </template>

    <IdentityShellState
      v-if="projectsStatus === 'pending' || projectResourcesStatus === 'pending'"
      state="loading"
      title="Loading applications"
    />
    <IdentityShellState
      v-else-if="projectsError"
      state="error"
      title="Unable to load applications"
      :description="projectsError.statusMessage || 'The identity service did not return the hosted applications.'"
      @retry="refresh"
    />

    <template v-else>
      <IdentityCollectionToolbar
        v-model="collection.search.value"
        placeholder="Search applications, projects, or URLs"
        :result-count="collection.total.value"
      >
        <template #filters>
          <USelect
            v-model="environment"
            :items="[
              { label: 'All environments', value: 'all' },
              { label: 'Live', value: 'live' },
              { label: 'Sandbox', value: 'sandbox' }
            ]"
            size="lg"
            class="w-full sm:w-48"
          />
          <USelect
            v-model="statusFilter"
            :items="[
              { label: 'All statuses', value: 'all' },
              { label: 'Active', value: 'active' },
              { label: 'Disabled', value: 'disabled' }
            ]"
            size="lg"
            class="w-full sm:w-40"
          />
        </template>
      </IdentityCollectionToolbar>

      <IdentityTableCard
        title="Application directory"
        description="Manage hosted application configuration from its owning project."
        :count="collection.total.value"
      >
        <IdentityApplicationList
          :items="collection.paginatedItems.value"
          show-project
          show-base-url
          :config-path="applicationConfigPath"
        />

        <template
          v-if="collection.total.value > collection.pageSize"
          #footer
        >
          <p class="text-sm text-muted">
            Page {{ collection.page.value }} of {{ Math.ceil(collection.total.value / collection.pageSize) }}
          </p>
          <UPagination
            v-model:page="collection.page.value"
            :total="collection.total.value"
            :items-per-page="collection.pageSize"
            size="sm"
          />
        </template>
      </IdentityTableCard>
    </template>
  </IdentityPanel>
</template>

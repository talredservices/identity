<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { IdentityClient } from '#admin/types/identity-access'
import type { IdentityClientDirectoryEntry } from '#admin/app/composables/useIdentityProjectResources'

definePageMeta({ layout: 'identity-admin', middleware: ['identity-admin'] })

const localePath = useLocalePath()
const router = useRouter()
const route = useRoute()
const access = useIdentityAccess()
const { data: projects, status: projectsStatus, error: projectsError, refresh: refreshProjects } = await access.projects()
const {
  clientEntries,
  projectResourcesStatus,
  refreshProjectResources
} = await useIdentityProjectResources(projects, 'identity-clients-directory')

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
const statusFilter = ref<'all' | IdentityClient['status']>(
  queryValue('status') === 'active' || queryValue('status') === 'disabled'
    ? queryValue('status') as IdentityClient['status']
    : 'all'
)
const syncingRoute = ref(false)

const scopedClients = computed(() => clientEntries.value.filter((entry) => {
  if (environment.value !== 'all' && entry.project.mode !== environment.value) return false
  if (statusFilter.value !== 'all' && entry.client.status !== statusFilter.value) return false
  return true
}))
const collection = useIdentityCollection(scopedClients, entry => (
  `${entry.client.name} ${entry.client.secret_prefix} ${entry.project.name} ${entry.project.slug} ${entry.project.mode} ${entry.client.status} ${entry.applications.map(application => application.name).join(' ')}`
))
collection.search.value = queryValue('q') ?? ''
const routePage = Number(queryValue('page'))
if (Number.isInteger(routePage) && routePage > 0) collection.page.value = routePage

const columns: TableColumn<IdentityClientDirectoryEntry>[] = [
  { accessorKey: 'client.name', header: 'Client' },
  { accessorKey: 'project.name', header: 'Project' },
  { accessorKey: 'project.mode', header: 'Environment' },
  { accessorKey: 'client.status', header: 'Status' },
  { accessorKey: 'client.secret_prefix', header: 'Secret prefix' },
  { id: 'applications', header: 'Applications' },
  { id: 'actions', header: '' }
]

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
</script>

<template>
  <IdentityPanel
    panel-id="identity-clients"
    title="Clients"
    icon="i-lucide-key-round"
    description="Review the application credentials connected to each identity project."
  >
    <template #actions>
      <UButton
        label="Refresh clients"
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
      title="Loading clients"
    />
    <IdentityShellState
      v-else-if="projectsError"
      state="error"
      title="Unable to load clients"
      :description="projectsError.statusMessage || 'The identity service did not return the project clients.'"
      @retry="refresh"
    />

    <template v-else>
      <IdentityCollectionToolbar
        v-model="collection.search.value"
        placeholder="Search clients, projects, or secret prefixes"
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
        title="Client directory"
        description="Manage credentials from the project where each client is defined."
        :count="collection.total.value"
      >
        <UTable
          :data="collection.paginatedItems.value"
          :columns="columns"
          empty="No clients match the current filters."
          class="hidden min-w-6xl md:block"
          :ui="{
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'border-b border-default',
            td: 'border-b border-default'
          }"
        >
          <template #[`client.name-cell`]="{ row }">
            <div class="min-w-0 py-1">
              <p class="truncate font-medium text-highlighted">
                {{ row.original.client.name }}
              </p>
              <p class="truncate text-xs text-muted">
                {{ row.original.applications.length }} connected application{{ row.original.applications.length === 1 ? '' : 's' }}
              </p>
            </div>
          </template>
          <template #[`project.name-cell`]="{ row }">
            <NuxtLink
              :to="localePath(`/admin/projects/${row.original.project.id}`)"
              class="font-medium text-highlighted hover:text-primary"
            >
              {{ row.original.project.name }}
            </NuxtLink>
          </template>
          <template #[`project.mode-cell`]="{ row }">
            <UBadge
              :color="row.original.project.mode === 'live' ? 'success' : 'warning'"
              variant="soft"
              class="capitalize"
            >
              {{ row.original.project.mode }}
            </UBadge>
          </template>
          <template #[`client.status-cell`]="{ row }">
            <UBadge
              :color="row.original.client.status === 'active' ? 'success' : 'error'"
              variant="soft"
            >
              {{ row.original.client.status === 'active' ? 'Active' : 'Disabled' }}
            </UBadge>
          </template>
          <template #[`client.secret_prefix-cell`]="{ row }">
            <code class="font-mono text-sm text-muted">{{ row.original.client.secret_prefix }}</code>
          </template>
          <template #applications-cell="{ row }">
            <span class="tabular-nums">{{ row.original.applications.length }}</span>
          </template>
          <template #actions-cell="{ row }">
            <div class="flex justify-end">
              <UButton
                label="Manage"
                icon="i-lucide-arrow-up-right"
                color="neutral"
                variant="ghost"
                :to="localePath(`/admin/projects/${row.original.project.id}?tab=clients`)"
              />
            </div>
          </template>
        </UTable>

        <div class="divide-y divide-default md:hidden">
          <article
            v-for="entry in collection.paginatedItems.value"
            :key="entry.client.id"
            class="space-y-3 p-4"
          >
            <div class="flex min-w-0 items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="break-words font-medium text-highlighted">
                  {{ entry.client.name }}
                </p>
                <code class="mt-1 block break-all font-mono text-xs text-muted">{{ entry.client.secret_prefix }}</code>
              </div>
              <UBadge
                :color="entry.client.status === 'active' ? 'success' : 'error'"
                variant="soft"
                class="shrink-0"
              >
                {{ entry.client.status === 'active' ? 'Active' : 'Disabled' }}
              </UBadge>
            </div>
            <div class="grid min-w-0 gap-3 text-sm sm:grid-cols-2">
              <div class="min-w-0">
                <p class="text-xs font-medium text-muted">
                  Project
                </p>
                <NuxtLink
                  :to="localePath(`/admin/projects/${entry.project.id}`)"
                  class="break-words font-medium text-highlighted hover:text-primary"
                >
                  {{ entry.project.name }}
                </NuxtLink>
                <UBadge
                  :color="entry.project.mode === 'live' ? 'success' : 'warning'"
                  variant="soft"
                  class="ml-2 capitalize"
                >
                  {{ entry.project.mode }}
                </UBadge>
              </div>
              <div>
                <p class="text-xs font-medium text-muted">
                  Connected applications
                </p>
                <p class="mt-1 tabular-nums text-highlighted">
                  {{ entry.applications.length }}
                </p>
              </div>
            </div>
            <div class="flex justify-end border-t border-default pt-2">
              <UButton
                label="Manage client"
                icon="i-lucide-arrow-up-right"
                color="neutral"
                variant="ghost"
                :to="localePath(`/admin/projects/${entry.project.id}?tab=clients`)"
              />
            </div>
          </article>
          <p
            v-if="!collection.paginatedItems.value.length"
            class="p-4 text-sm text-muted"
          >
            No clients match the current filters.
          </p>
        </div>

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

<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { IdentityProject } from '#admin/types/identity-access'

definePageMeta({ layout: 'identity-admin', middleware: ['identity-admin'] })

const localePath = useLocalePath()
const route = useRoute()
const router = useRouter()
const { projects: fetchProjects, createProject } = useIdentityAccess()
const { data: projects, status, error, refresh } = await fetchProjects()
const open = ref(false)
const saving = ref(false)
const queryValue = (key: string): string | undefined => {
  const value = route.query[key]
  const candidate = Array.isArray(value) ? value[0] : value
  return typeof candidate === 'string' ? candidate : undefined
}
const routeMode = queryValue('environment')
const routeStatus = queryValue('status')
const mode = ref<'all' | 'live' | 'sandbox'>(routeMode === 'live' || routeMode === 'sandbox' ? routeMode : 'all')
const includeDisabled = ref(queryValue('include_disabled') === '1')
const projectStatus = ref<'all' | IdentityProject['status']>(
  includeDisabled.value && (routeStatus === 'active' || routeStatus === 'suspended' || routeStatus === 'pending_deletion') ? routeStatus : 'all'
)
const syncingRoute = ref(false)
const form = reactive({ name: '', slug: '', description: '' })

const scopedProjects = computed(() => (projects.value ?? []).filter((project) => {
  if (!includeDisabled.value && project.status !== 'active') return false
  if (includeDisabled.value && projectStatus.value !== 'all' && project.status !== projectStatus.value) return false
  return mode.value === 'all' || project.mode === mode.value
}))
const collection = useIdentityCollection(scopedProjects, project => (
  `${project.name} ${project.slug} ${project.description ?? ''} ${project.status} ${project.mode}`
))
collection.search.value = queryValue('q') ?? ''
const routePage = Number(queryValue('page'))
if (Number.isInteger(routePage) && routePage > 0) collection.page.value = routePage

const columns: TableColumn<IdentityProject>[] = [
  { accessorKey: 'name', header: 'Project' },
  { accessorKey: 'mode', header: 'Environment' },
  { accessorKey: 'registration_mode', header: 'Registration' },
  { accessorKey: 'status', header: 'Status' },
  { id: 'actions', header: '' }
]

watch(() => form.name, (name) => {
  if (!form.slug) form.slug = name.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
})

watch([mode, includeDisabled, projectStatus, collection.search], () => {
  if (!syncingRoute.value) collection.page.value = 1
})

watch([mode, includeDisabled, projectStatus, collection.search, collection.page], async () => {
  if (syncingRoute.value) return
  const query: Record<string, string> = {}
  if (collection.search.value.trim()) query.q = collection.search.value.trim()
  if (mode.value !== 'all') query.environment = mode.value
  if (includeDisabled.value) {
    query.include_disabled = '1'
    if (projectStatus.value !== 'all') query.status = projectStatus.value
  }
  if (collection.page.value > 1) query.page = String(collection.page.value)
  await router.replace({ query })
})

watch(() => route.query, async () => {
  syncingRoute.value = true
  const nextMode = queryValue('environment')
  const nextStatus = queryValue('status')
  mode.value = nextMode === 'live' || nextMode === 'sandbox' ? nextMode : 'all'
  includeDisabled.value = queryValue('include_disabled') === '1'
  projectStatus.value = includeDisabled.value && (nextStatus === 'active' || nextStatus === 'suspended' || nextStatus === 'pending_deletion')
    ? nextStatus
    : 'all'
  collection.search.value = queryValue('q') ?? ''
  const nextPage = Number(queryValue('page'))
  collection.page.value = Number.isInteger(nextPage) && nextPage > 0 ? nextPage : 1
  await nextTick()
  syncingRoute.value = false
})

async function submit() {
  saving.value = true
  try {
    await createProject({ ...form, description: form.description || null })
    Object.assign(form, { name: '', slug: '', description: '' })
    open.value = false
    await refresh()
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <IdentityPanel
    panel-id="identity-projects"
    title="Projects"
    icon="i-lucide-folder-key"
    description="Manage the applications, environments, and access boundaries that trust this identity installation."
  >
    <IdentityShellState
      v-if="status === 'pending'"
      state="loading"
      title="Loading projects"
    />
    <IdentityShellState
      v-else-if="error"
      state="error"
      title="Unable to load projects"
      :description="error.statusMessage || 'The identity service did not return the project list.'"
      @retry="refresh()"
    />

    <template v-else>
      <IdentityCollectionToolbar
        v-model="collection.search.value"
        placeholder="Search projects"
        :result-count="collection.total.value"
      >
        <template #filters>
          <USelect
            v-model="mode"
            :items="[
              { label: 'All environments', value: 'all' },
              { label: 'Live', value: 'live' },
              { label: 'Sandbox', value: 'sandbox' }
            ]"
            size="lg"
            class="w-full sm:w-48"
          />
          <UCheckbox
            v-model="includeDisabled"
            label="Include disabled projects"
          />
          <USelect
            v-if="includeDisabled"
            v-model="projectStatus"
            :items="[
              { label: 'All statuses', value: 'all' },
              { label: 'Active', value: 'active' },
              { label: 'Suspended', value: 'suspended' },
              { label: 'Pending deletion', value: 'pending_deletion' }
            ]"
            size="lg"
            class="w-full sm:w-52"
          />
        </template>

        <template #actions>
          <UButton
            label="New project"
            icon="i-lucide-folder-plus"
            @click="() => { open = true }"
          />
        </template>
      </IdentityCollectionToolbar>

      <IdentityTableCard
        title="Project directory"
        description="Open a project to manage members, clients, permissions, webhooks, and audit history."
        :count="collection.total.value"
      >
        <UTable
          :data="collection.paginatedItems.value"
          :columns="columns"
          empty="No projects match the current filters."
          class="hidden min-w-4xl md:block"
          :ui="{
            thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
            tbody: '[&>tr]:last:[&>td]:border-b-0',
            th: 'border-b border-default',
            td: 'border-b border-default'
          }"
        >
          <template #name-cell="{ row }">
            <div class="min-w-0 py-1">
              <NuxtLink
                :to="localePath(`/admin/projects/${row.original.id}`)"
                class="font-medium text-highlighted hover:text-primary"
              >
                {{ row.original.name }}
              </NuxtLink>
              <p class="max-w-sm truncate text-xs text-muted">
                {{ row.original.description || row.original.slug }}
              </p>
            </div>
          </template>
          <template #mode-cell="{ row }">
            <UBadge
              :color="row.original.mode === 'live' ? 'success' : 'warning'"
              variant="soft"
              class="capitalize"
            >
              {{ row.original.mode }}
            </UBadge>
          </template>
          <template #registration_mode-cell="{ row }">
            <span class="text-sm text-muted">
              {{ row.original.registration_mode === 'public' ? 'Public' : 'Invite only' }}
            </span>
          </template>
          <template #status-cell="{ row }">
            <div class="flex items-center gap-2 text-sm capitalize">
              <span
                class="size-2 rounded-full"
                :class="row.original.status === 'active' ? 'bg-success' : row.original.status === 'pending_deletion' ? 'bg-error' : 'bg-warning'"
              />
              {{ row.original.status }}
            </div>
          </template>
          <template #actions-cell="{ row }">
            <div class="flex justify-end">
              <UButton
                label="Manage"
                icon="i-lucide-arrow-up-right"
                color="neutral"
                variant="ghost"
                :to="localePath(`/admin/projects/${row.original.id}`)"
              />
            </div>
          </template>
        </UTable>

        <div class="divide-y divide-default md:hidden">
          <article
            v-for="project in collection.paginatedItems.value"
            :key="project.id"
            class="space-y-3 p-4"
          >
            <div class="flex min-w-0 items-start justify-between gap-3">
              <div class="min-w-0">
                <NuxtLink
                  :to="localePath(`/admin/projects/${project.id}`)"
                  class="break-words font-medium text-highlighted hover:text-primary"
                >
                  {{ project.name }}
                </NuxtLink>
                <p class="mt-1 break-words text-xs text-muted">
                  {{ project.description || project.slug }}
                </p>
              </div>
              <UBadge
                :color="project.status === 'active' ? 'success' : project.status === 'pending_deletion' ? 'error' : 'warning'"
                variant="soft"
                class="shrink-0 capitalize"
              >
                {{ project.status.replace('_', ' ') }}
              </UBadge>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <UBadge
                :color="project.mode === 'live' ? 'success' : 'warning'"
                variant="soft"
                class="capitalize"
              >
                {{ project.mode }}
              </UBadge>
              <span class="text-muted">
                {{ project.registration_mode === 'public' ? 'Public registration' : 'Invite only' }}
              </span>
            </div>
            <div class="flex justify-end border-t border-default pt-2">
              <UButton
                label="Manage project"
                icon="i-lucide-arrow-up-right"
                color="neutral"
                variant="ghost"
                :to="localePath(`/admin/projects/${project.id}`)"
              />
            </div>
          </article>
          <p
            v-if="!collection.paginatedItems.value.length"
            class="p-4 text-sm text-muted"
          >
            No projects match the current filters.
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

    <UModal
      v-model:open="open"
      title="Create project"
      description="A project isolates its clients, members, roles, and permissions."
    >
      <template #body>
        <form
          class="space-y-4"
          @submit.prevent="submit"
        >
          <UFormField
            label="Name"
            required
          >
            <UInput
              v-model="form.name"
              autofocus
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="Slug"
            required
          >
            <UInput
              v-model="form.slug"
              class="w-full"
            />
          </UFormField>
          <UFormField label="Description">
            <UTextarea
              v-model="form.description"
              class="w-full"
            />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancel"
              color="neutral"
              variant="ghost"
              @click="() => { open = false }"
            />
            <UButton
              type="submit"
              label="Create project"
              icon="i-lucide-folder-plus"
              :loading="saving"
            />
          </div>
        </form>
      </template>
    </UModal>
  </IdentityPanel>
</template>

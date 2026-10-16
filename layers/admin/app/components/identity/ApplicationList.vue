<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { IdentityApplicationListItem, IdentityHostedApplication } from '#admin/types/identity-access'
import { identityHostedApplicationPublicUrl } from '#admin/app/utils/identity-hosted-experiences'

const props = withDefaults(defineProps<{
  items: IdentityApplicationListItem[]
  showProject?: boolean
  showBaseUrl?: boolean
  disabled?: boolean
  tableClass?: string
  configPath?: (application: IdentityHostedApplication) => string
}>(), {
  showProject: false,
  showBaseUrl: false,
  disabled: false,
  tableClass: '',
  configPath: undefined
})

const localePath = useLocalePath()

const columns = computed<TableColumn<IdentityApplicationListItem>[]>(() => [
  { id: 'application', header: 'Application' },
  ...(props.showProject ? [{ id: 'project', header: 'Project' } satisfies TableColumn<IdentityApplicationListItem>] : []),
  { id: 'clients', header: 'Clients' },
  { id: 'web', header: 'Web' },
  { id: 'status', header: 'Status' },
  { id: 'actions', header: '' }
])

function configurationPath(application: IdentityHostedApplication): string | undefined {
  return props.configPath?.(application)
}
</script>

<template>
  <div
    v-if="props.items.length"
    class="overflow-hidden rounded-xl border border-default"
  >
    <UTable
      :data="props.items"
      :columns="columns"
      class="hidden md:block"
      :class="props.tableClass"
      :ui="{
        base: props.tableClass,
        thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
        tbody: '[&>tr]:last:[&>td]:border-b-0',
        th: 'border-b border-default',
        td: 'border-b border-default'
      }"
      empty="No applications match the current filters."
    >
      <template #application-cell="{ row }">
        <div class="min-w-0 py-1">
          <p class="truncate font-medium text-highlighted">
            {{ row.original.application.name }}
          </p>
          <p class="truncate font-mono text-xs text-muted">
            {{ row.original.application.key }}
          </p>
          <p
            v-if="props.showBaseUrl"
            class="truncate text-xs text-muted"
            :title="row.original.application.application_url"
          >
            {{ row.original.application.application_url }}
          </p>
        </div>
      </template>

      <template #project-cell="{ row }">
        <NuxtLink
          :to="localePath(`/admin/projects/${row.original.project.id}`)"
          class="block min-w-0 font-medium text-highlighted hover:text-primary"
        >
          <span class="block truncate">{{ row.original.project.name }}</span>
          <UBadge
            :color="row.original.project.mode === 'live' ? 'success' : 'warning'"
            variant="soft"
            class="mt-1 capitalize"
          >
            {{ row.original.project.mode }}
          </UBadge>
        </NuxtLink>
      </template>

      <template #clients-cell="{ row }">
        <div class="min-w-0 max-w-48">
          <p class="truncate text-sm text-highlighted">
            {{ row.original.clients.map(client => client.name).join(', ') || 'Not connected' }}
          </p>
          <p
            v-if="row.original.application.sandbox_client_id"
            class="text-xs text-muted"
          >
            Primary + sandbox
          </p>
        </div>
      </template>

      <template #web-cell="{ row }">
        <UTooltip text="Open application website">
          <UButton
            icon="i-lucide-globe"
            color="neutral"
            variant="ghost"
            square
            :aria-label="`Open ${row.original.application.name} website`"
            :to="identityHostedApplicationPublicUrl(row.original.application)"
            target="_blank"
            rel="noopener noreferrer"
          />
        </UTooltip>
      </template>

      <template #status-cell="{ row }">
        <UBadge
          :color="row.original.application.status === 'active' ? 'success' : 'neutral'"
          variant="soft"
          class="capitalize"
        >
          {{ row.original.application.status }}
        </UBadge>
      </template>

      <template #actions-cell="{ row }">
        <div class="flex justify-end gap-1">
          <slot
            name="actions"
            :item="row.original"
          >
            <UTooltip
              v-if="props.configPath"
              text="Configure application"
            >
              <UButton
                icon="i-lucide-settings-2"
                color="neutral"
                variant="outline"
                square
                :aria-label="`Configure ${row.original.application.name}`"
                :disabled="props.disabled"
                :to="configurationPath(row.original.application)"
              />
            </UTooltip>
          </slot>
        </div>
      </template>
    </UTable>

    <div class="divide-y divide-default md:hidden">
      <article
        v-for="item in props.items"
        :key="item.application.id"
        class="space-y-3 p-4"
      >
        <div class="flex min-w-0 items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="break-words font-medium text-highlighted">
              {{ item.application.name }}
            </p>
            <p class="break-all font-mono text-xs text-muted">
              {{ item.application.key }}
            </p>
            <p
              v-if="props.showBaseUrl"
              class="break-all text-xs text-muted"
            >
              {{ item.application.application_url }}
            </p>
          </div>
          <UBadge
            :color="item.application.status === 'active' ? 'success' : 'neutral'"
            variant="soft"
            class="shrink-0 capitalize"
          >
            {{ item.application.status }}
          </UBadge>
        </div>

        <div
          v-if="props.showProject"
          class="text-sm"
        >
          <p class="text-xs font-medium text-muted">
            Project
          </p>
          <NuxtLink
            :to="localePath(`/admin/projects/${item.project.id}`)"
            class="font-medium text-highlighted hover:text-primary"
          >{{ item.project.name }}</NuxtLink>
          <UBadge
            :color="item.project.mode === 'live' ? 'success' : 'warning'"
            variant="soft"
            class="ml-2 capitalize"
          >
            {{ item.project.mode }}
          </UBadge>
        </div>

        <div class="min-w-0 text-sm">
          <p class="text-xs font-medium text-muted">
            Clients
          </p>
          <p class="break-words text-highlighted">
            {{ item.clients.map(client => client.name).join(', ') || 'Not connected' }}
          </p>
          <p
            v-if="item.application.sandbox_client_id"
            class="text-xs text-muted"
          >
            Primary + sandbox
          </p>
        </div>

        <div class="flex items-center justify-between gap-2 border-t border-default pt-2">
          <UButton
            v-if="identityHostedApplicationPublicUrl(item.application)"
            label="Open website"
            icon="i-lucide-globe"
            color="neutral"
            variant="ghost"
            :to="identityHostedApplicationPublicUrl(item.application)"
            target="_blank"
            rel="noopener noreferrer"
          />
          <div class="ml-auto flex gap-1">
            <slot
              name="actions"
              :item="item"
            >
              <UButton
                v-if="props.configPath"
                icon="i-lucide-settings-2"
                color="neutral"
                variant="outline"
                square
                :aria-label="`Configure ${item.application.name}`"
                :disabled="props.disabled"
                :to="configurationPath(item.application)"
              />
            </slot>
          </div>
        </div>
      </article>
    </div>
  </div>
  <p
    v-else
    class="py-8 text-center text-sm text-muted"
  >
    No applications match the current filters.
  </p>
</template>

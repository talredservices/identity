<script setup lang="ts">
import PageTitle from '../PageTitle.vue'

const { t } = useI18n()
const props = withDefaults(defineProps<{
  panelId: string
  title: string
  icon?: string
  description?: string
  backTo?: string
  backLabel?: string
  bodyClass?: string
  containerClass?: string
}>(), {
  description: undefined,
  icon: undefined,
  backTo: undefined,
  backLabel: undefined,
  bodyClass: 'mx-auto flex w-full max-w-[1600px] flex-col gap-6',
  containerClass: 'max-w-none'
})

const resolvedBackLabel = computed(() => {
  return props.backLabel ?? t('admin.common.back')
})
</script>

<template>
  <UDashboardPanel :id="props.panelId">
    <template #header>
      <UDashboardNavbar>
        <template #title>
          <div class="flex min-w-0 items-center gap-2">
            <UTooltip :text="t('identityConsole.toggleSidebar')">
              <UDashboardSidebarCollapse
                class="hidden size-6 shrink-0 p-0 lg:inline-flex"
                :aria-label="t('identityConsole.toggleSidebar')"
              />
            </UTooltip>

            <slot
              v-if="$slots.title"
              name="title"
            />
            <PageTitle
              v-else
              :title="props.title"
              :icon="props.icon"
            />
          </div>
        </template>

        <template #right>
          <slot name="leading" />

          <div
            v-if="props.backTo || $slots.actions"
            class="flex items-center gap-2"
          >
            <UButton
              v-if="props.backTo"
              :label="resolvedBackLabel"
              color="neutral"
              variant="ghost"
              icon="i-lucide-arrow-left"
              :to="props.backTo"
            />

            <slot name="actions" />
          </div>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UContainer :class="['w-full py-6 sm:py-8', props.containerClass]">
        <div :class="props.bodyClass">
          <div
            v-if="props.description"
            class="flex max-w-3xl items-start gap-2 rounded-lg border border-default/60 bg-elevated/40 px-3 py-2 text-sm font-medium leading-6 text-muted"
          >
            <UIcon
              name="i-lucide-info"
              class="mt-0.5 size-4 shrink-0 text-muted"
            />
            <p>
              {{ props.description }}
            </p>
          </div>

          <slot />
        </div>
      </UContainer>
    </template>
  </UDashboardPanel>
</template>

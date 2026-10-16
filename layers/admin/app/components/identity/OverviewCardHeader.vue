<script setup lang="ts">
const props = withDefaults(defineProps<{
  title: string
  titleTo?: string
  subtitle?: string
  subtitleTo?: string
  manageTo?: string
  manageLabel?: string
}>(), {
  titleTo: undefined,
  subtitle: undefined,
  subtitleTo: undefined,
  manageTo: undefined,
  manageLabel: 'Manage'
})
</script>

<template>
  <div class="flex items-start justify-between gap-2">
    <div class="min-w-0">
      <NuxtLink
        v-if="props.titleTo"
        :to="props.titleTo"
        class="block truncate text-sm font-semibold leading-5 text-highlighted transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        {{ props.title }}
      </NuxtLink>
      <p
        v-else
        class="truncate text-sm font-semibold leading-5 text-highlighted"
      >
        {{ props.title }}
      </p>

      <NuxtLink
        v-if="props.subtitleTo && props.subtitle"
        :to="props.subtitleTo"
        class="mt-1 block truncate text-xs leading-5 text-muted transition-colors hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        {{ props.subtitle }}
      </NuxtLink>
      <p
        v-else-if="props.subtitle"
        class="mt-1 truncate text-xs leading-5 text-muted"
      >
        {{ props.subtitle }}
      </p>
    </div>

    <UButton
      v-if="props.manageTo"
      :label="props.manageLabel"
      icon="i-lucide-settings-2"
      color="neutral"
      variant="outline"
      size="xs"
      class="shrink-0 border-default/70 bg-default/20 transition-colors hover:border-primary/35 hover:text-primary"
      :to="props.manageTo"
    />
  </div>
</template>

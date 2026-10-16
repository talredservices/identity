<script setup lang="ts">
const props = defineProps<{
  label: string
}>()
</script>

<template>
  <dl class="min-w-0">
    <dt class="truncate text-[11px] font-medium text-muted">
      {{ props.label }}
    </dt>
    <dd class="mt-1 min-w-0 text-sm font-medium leading-5 text-highlighted">
      <slot />
    </dd>
  </dl>
</template>

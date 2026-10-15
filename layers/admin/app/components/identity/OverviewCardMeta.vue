<script setup lang="ts">
const props = withDefaults(defineProps<{
  layout?: 'inline' | 'grid'
}>(), {
  layout: 'inline'
})

const layoutClass = computed(() => props.layout === 'grid'
  ? 'grid grid-cols-2 gap-x-3 gap-y-3 sm:grid-cols-3'
  : 'flex flex-wrap items-center gap-1.5')
</script>

<template>
  <div :class="['mt-4', layoutClass]">
    <slot />
  </div>
</template>

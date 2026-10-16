<script setup lang="ts">
const props = withDefaults(defineProps<{
  title: string
  icon?: string
}>(), {
  icon: undefined
})
</script>

<template>
  <div class="flex items-center gap-2">
    <UIcon
      v-if="props.icon"
      :name="props.icon"
      class="size-5 shrink-0 text-primary"
    />
    <span class="truncate font-semibold text-highlighted">
      {{ props.title }}
    </span>
  </div>
</template>

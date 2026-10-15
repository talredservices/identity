<script setup lang="ts">
const props = withDefaults(defineProps<{
  placeholder?: string
  resultCount?: number
}>(), {
  placeholder: 'Search',
  resultCount: undefined
})

const search = defineModel<string>({ default: '' })
</script>

<template>
  <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <div class="flex min-w-0 flex-1 flex-col gap-2 sm:flex-row sm:items-center">
      <UInput
        v-model="search"
        icon="i-lucide-search"
        :placeholder="props.placeholder"
        :aria-label="props.placeholder"
        size="lg"
        class="w-full sm:max-w-sm"
        :trailing-icon="search ? 'i-lucide-x' : undefined"
        @click:trailing="search = ''"
      />
      <slot name="filters" />
      <span
        v-if="props.resultCount !== undefined"
        class="shrink-0 text-sm text-muted"
      >
        {{ props.resultCount }} result{{ props.resultCount === 1 ? '' : 's' }}
      </span>
    </div>
    <div
      v-if="$slots.actions"
      class="flex shrink-0 items-center gap-2"
    >
      <slot name="actions" />
    </div>
  </div>
</template>

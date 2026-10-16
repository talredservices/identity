<script setup lang="ts">
const props = defineProps<{
  headingId: string
  title: string
  description?: string
}>()
</script>

<template>
  <section
    :aria-labelledby="props.headingId"
    class="space-y-4 rounded-2xl border border-default/70 p-4 sm:p-5"
  >
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div class="min-w-0">
        <h2
          :id="props.headingId"
          class="text-lg font-semibold text-highlighted"
        >
          {{ props.title }}
        </h2>
        <p
          v-if="props.description"
          class="mt-1 text-sm text-muted"
        >
          {{ props.description }}
        </p>
      </div>

      <div
        v-if="$slots.actions"
        class="flex flex-wrap items-center gap-2"
      >
        <slot name="actions" />
      </div>
    </div>

    <slot />
  </section>
</template>

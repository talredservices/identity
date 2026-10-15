<script setup lang="ts">
withDefaults(defineProps<{
  icon?: string
  iconClass?: string
  tone?: 'primary' | 'secondary'
}>(), {
  icon: undefined,
  iconClass: undefined,
  tone: 'primary'
})

const toneClasses = {
  primary: {
    icon: 'text-primary',
    surface: 'border-primary/20 bg-primary/10',
    hover: 'hover:border-primary/40'
  },
  secondary: {
    icon: 'text-secondary',
    surface: 'border-secondary/20 bg-secondary/10',
    hover: 'hover:border-secondary/40'
  }
} as const
</script>

<template>
  <article
    class="flex min-w-0 flex-col rounded-xl border border-default/80 bg-elevated/35 p-4 transition-colors hover:bg-elevated/55"
    :class="toneClasses[tone].hover"
  >
    <div class="flex items-start gap-3">
      <span
        v-if="icon"
        class="flex size-10 shrink-0 items-center justify-center rounded-lg border"
        :class="toneClasses[tone].surface"
      >
        <UIcon
          :name="icon"
          class="size-5"
          :class="iconClass || toneClasses[tone].icon"
        />
      </span>
      <slot
        v-else
        name="icon"
      />

      <div class="min-w-0 flex-1">
        <slot name="header" />
        <slot name="meta" />
      </div>
    </div>

    <slot />
  </article>
</template>

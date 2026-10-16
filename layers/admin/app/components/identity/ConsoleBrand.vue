<script setup lang="ts">
const props = withDefaults(defineProps<{
  to: string
  collapsed?: boolean
}>(), {
  collapsed: false
})

const localePath = useLocalePath()
const { t } = useI18n()
const accessibleName = computed(() => `${t('identityConsole.brand')} ${t('identityConsole.product')}`)
</script>

<template>
  <NuxtLink
    :to="localePath(props.to)"
    class="flex min-w-0 items-center gap-2 rounded-lg p-2 font-semibold tracking-[-0.04em] text-highlighted transition hover:bg-elevated focus-visible:outline-2 focus-visible:outline-primary"
    :class="props.collapsed ? 'justify-center' : undefined"
    :aria-label="accessibleName"
  >
    <IdentityAuthBrandMark :size="props.collapsed ? 'compact' : 'header'" />

    <template v-if="!props.collapsed">
      <span class="flex flex-col justify-center gap-0 leading-none">
        <span class="text-xl leading-none">Talred</span>
        <span class="translate-x-4 text-[0.625rem] leading-none font-medium tracking-[0.04em]">
          <span class="text-highlighted">Services</span><span class="text-primary">.com</span>
        </span>
      </span>
      <span class="mt-0.5 ms-4 inline-flex shrink-0 rounded-full border border-primary/25 bg-primary/10 px-1.5 py-0.5 text-[0.625rem] leading-none font-semibold tracking-wide text-primary">
        {{ t('identityConsole.product') }}
      </span>
    </template>
  </NuxtLink>
</template>

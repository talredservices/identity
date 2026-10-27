<script setup lang="ts">
const props = withDefaults(defineProps<{
  title: string
  description: string
  showBrand?: boolean
}>(), {
  showBrand: false
})
</script>

<template>
  <section class="identity-auth-card">
    <IdentityAuthFormHeader
      :title="props.title"
      :description="props.description"
      :show-brand="props.showBrand"
    />
    <slot />
  </section>
</template>

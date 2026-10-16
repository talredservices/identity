<script setup lang="ts">
import type { ComputedRef } from 'vue'

defineProps<{
  title: string
  description?: string
}>()

const brand = inject<ComputedRef<{
  presentation: { logoUrl?: string | null, accentColor?: string | null, welcomeText?: string | null }
} | null>>('identity-auth-brand', computed(() => null))

const welcomeText = computed(() => brand.value?.presentation.welcomeText)
</script>

<template>
  <div class="identity-auth-form-header">
    <IdentityAuthBrandMark
      :logo-url="brand?.presentation.logoUrl"
      :accent-color="brand?.presentation.accentColor"
    />
    <h1>
      {{ title }}
    </h1>
    <p
      v-if="description"
      class="identity-auth-form-header-description"
    >
      {{ description }}
    </p>
    <p
      v-if="welcomeText"
      class="identity-auth-form-header-welcome"
    >
      {{ welcomeText }}
    </p>
    <slot />
  </div>
</template>

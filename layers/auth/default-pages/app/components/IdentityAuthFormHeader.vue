<script setup lang="ts">
import type { ComputedRef } from 'vue'

const brand = inject<ComputedRef<{
  presentation: { logoUrl?: string | null, accentColor?: string | null, welcomeText?: string | null }
} | null>>('identity-auth-brand', computed(() => null))
const pageSet = inject<ComputedRef<string> | string>('identity-auth-page-set', 'default')
const showHostedLoginBrandMark = inject<boolean>('identity-auth-show-login-brand-mark', false)
const route = useRoute()
const props = defineProps<{
  title: string
  description?: string
  showBrand?: boolean
}>()
const isLoginRoute = computed(() => /^\/auth\/(?:[^/]+\/)?login$/.test(route.path))
const showIdentityMark = computed(() =>
  props.showBrand
  || unref(pageSet) === 'default'
  || (showHostedLoginBrandMark && isLoginRoute.value)
)

const welcomeText = computed(() => brand.value?.presentation.welcomeText)
</script>

<template>
  <div class="identity-auth-form-header">
    <IdentityAuthBrandMark
      v-if="showIdentityMark"
      :logo-url="brand?.presentation.logoUrl"
      :accent-color="brand?.presentation.accentColor"
    />
    <h1>
      {{ props.title }}
    </h1>
    <p
      v-if="props.description"
      class="identity-auth-form-header-description"
    >
      {{ props.description }}
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

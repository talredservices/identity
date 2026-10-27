<script setup lang="ts">
import type { ComputedRef } from 'vue'

definePageMeta({ layout: 'identity-auth' })

const brand = inject<ComputedRef<{
  authentication: { termsUrl: string | null, privacyUrl: string | null }
} | null>>('identity-auth-brand', computed(() => null))
const config = useRuntimeConfig()
const route = useRoute()
const mutate = useIdentityMutation()
const { logout } = useIdentityAuth()
const errorMessage = ref('')

onMounted(async () => {
  try {
    const application = typeof route.query.application === 'string' ? route.query.application : ''
    const intent = typeof route.query.intent === 'string' ? route.query.intent : ''
    if (application && intent) {
      const result = await mutate<{ redirectUrl: string }>('/api/hosted-auth/logout', {
        method: 'POST',
        body: { application, intent }
      })
      await navigateTo(result.redirectUrl, { external: true })
      return
    }
    await logout()
    await navigateTo(identitySafeRedirect(
      config.public.identityAuth.logoutRedirect,
      '/auth/login'
    ))
  } catch (error) {
    errorMessage.value = identityAuthErrorMessage(
      error,
      'We could not close your session.'
    )
  }
})
</script>

<template>
  <div class="identity-auth-page identity-auth-logout-page">
    <IdentityAuthCard
      title="Signing out"
      description="Closing your application session."
      show-brand
    >
      <p
        v-if="errorMessage"
        class="identity-auth-error"
      >
        {{ errorMessage }}
      </p>
    </IdentityAuthCard>

    <IdentityAttribution
      :terms-url="brand?.authentication.termsUrl"
      :privacy-url="brand?.authentication.privacyUrl"
      centered
    />
  </div>
</template>

<style scoped>
.identity-auth-logout-page {
  display: grid;
  gap: 0.75rem;
  width: min(100%, 28rem);
}

.identity-auth-logout-page :deep(.identity-auth-card) {
  width: 100%;
}
</style>

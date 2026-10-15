<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{
  error: NuxtError
}>()

const { locale, t } = useI18n()
const localePath = useLocalePath()
const statusCode = computed(() => props.error.statusCode || 500)
const isNotFound = computed(() => statusCode.value === 404)
const title = computed(() => isNotFound.value
  ? t('error.notFoundTitle')
  : t('error.genericTitle'))
const description = computed(() => isNotFound.value
  ? t('error.notFoundDescription')
  : t('error.genericDescription'))
const config = useRuntimeConfig()
const route = useRoute()
const hostedApplication = computed(() =>
  typeof route.query.application === 'string' ? route.query.application : null
)
const { data: hostedReturnUrl } = await useAsyncData(
  'identity-error-hosted-return-url',
  async () => {
    if (!hostedApplication.value) return null

    try {
      const response = await $fetch<{ application?: { returnUrl?: string } }>(
        '/api/hosted-auth/application',
        { query: { application: hostedApplication.value } }
      )

      return response.application?.returnUrl ?? null
    } catch {
      return null
    }
  },
  { watch: [hostedApplication] }
)
const recoveryPath = computed(() => {
  const publicHost = config.public.identityAdmin.publicHost.trim().toLowerCase()
  const host = useRequestURL().hostname.toLowerCase()

  return host === publicHost
    ? localePath('/auth/login')
    : localePath('/admin/sign-in')
})

useHead({
  htmlAttrs: { lang: locale }
})

useSeoMeta({
  title,
  description
})

async function recover() {
  if (hostedReturnUrl.value) {
    await clearError()
    window.location.assign(hostedReturnUrl.value)
    return
  }

  await clearError({ redirect: recoveryPath.value })
}

function reload() {
  reloadNuxtApp()
}
</script>

<template>
  <UApp>
    <main class="min-h-screen flex items-center justify-center px-6 py-16">
      <UPageCard
        variant="subtle"
        class="w-full max-w-xl"
      >
        <div class="flex flex-col items-center text-center gap-6 py-6">
          <IdentityAuthBrandMark size="header" />

          <div class="space-y-3">
            <p class="text-sm font-semibold text-primary">
              {{ statusCode }}
            </p>
            <h1 class="text-2xl sm:text-3xl font-semibold text-highlighted">
              {{ title }}
            </h1>
            <p class="text-muted max-w-md">
              {{ description }}
            </p>
          </div>

          <div class="flex flex-wrap justify-center gap-3">
            <UButton
              icon="i-lucide-house"
              :label="t('error.backHome')"
              @click="recover"
            />
            <UButton
              icon="i-lucide-rotate-ccw"
              :label="t('error.tryAgain')"
              color="neutral"
              variant="outline"
              @click="reload"
            />
          </div>
        </div>
      </UPageCard>
    </main>
  </UApp>
</template>

<script setup lang="ts">
definePageMeta({ layout: false })

const route = useRoute()
const localePath = useLocalePath()
const { t } = useI18n()
useHead({
  bodyAttrs: {
    class: 'identity-console-theme'
  }
})
const { login } = useIdentityAuth()
const form = reactive({ email: '', password: '' })
const pending = ref(false)
const errorMessage = ref('')

function resolveSignInError(error: unknown): string {
  const candidate = error as {
    statusCode?: number
    statusMessage?: string
    data?: {
      message?: string
      statusMessage?: string
    }
  }

  const rawMessage = candidate.data?.message
    ?? candidate.data?.statusMessage
    ?? candidate.statusMessage
    ?? ''
  const normalized = rawMessage.toLowerCase()
  const statusCode = candidate.statusCode

  if (
    statusCode === 400
    || statusCode === 422
    || normalized.includes('validation')
    || normalized.includes('required')
    || normalized.includes('invalid email')
    || normalized.includes('password confirmation')
  ) {
    return 'Please review the email and password fields and try again.'
  }

  if (
    statusCode === 401
    || normalized.includes('invalid credentials')
    || normalized.includes('unauthorized')
    || normalized.includes('incorrect password')
    || normalized.includes('wrong password')
    || normalized.includes('email or password')
  ) {
    return 'The email or password you entered is incorrect. Please try again.'
  }

  if (
    statusCode === 429
    || normalized.includes('too many attempts')
    || normalized.includes('rate limit')
  ) {
    return 'Too many sign-in attempts. Please wait a moment and try again.'
  }

  if (
    statusCode === 503
    || statusCode === 500
    || normalized.includes('temporarily unavailable')
    || normalized.includes('service unavailable')
    || normalized.includes('server')
  ) {
    return 'The identity service is temporarily unavailable. Please try again in a moment.'
  }

  return t('identityConsole.signIn.error')
}

async function submit() {
  pending.value = true
  errorMessage.value = ''
  try {
    await login(form)
    const projectsPath = localePath('/admin/projects')
    const installationUsersPath = localePath('/admin/identity-users')
    const requestedRedirect = typeof route.query.redirect === 'string'
      ? route.query.redirect
      : ''
    const destination = [projectsPath, installationUsersPath].some(path =>
      requestedRedirect === path || requestedRedirect.startsWith(`${path}/`)
    )
      ? requestedRedirect
      : projectsPath
    await navigateTo(destination)
  } catch (error: unknown) {
    errorMessage.value = resolveSignInError(error)
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="identity-admin-sign-in min-h-screen bg-default">
    <UHeader :ui="{ container: 'max-w-7xl' }">
      <template #left>
        <IdentityConsoleBrand to="/admin/sign-in" />
      </template>

      <template #right>
        <IdentityConsoleControls compact />
      </template>
    </UHeader>

    <main class="flex min-h-[calc(100vh-4rem)] items-center justify-center px-4 py-12 sm:px-6">
      <UPageCard class="w-full max-w-md">
        <div class="mb-6 space-y-3 text-center">
          <IdentityAuthBrandMark class="mx-auto" />
          <div class="space-y-1.5">
            <h1 class="text-xl font-semibold tracking-tight text-highlighted">
              {{ t('identityConsole.signIn.title') }}
            </h1>
            <p class="text-sm leading-6 text-muted">
              {{ t('identityConsole.signIn.description') }}
            </p>
          </div>
        </div>

        <form
          class="space-y-5"
          @submit.prevent="submit"
        >
          <UFormField
            :label="t('identityConsole.signIn.email')"
            required
          >
            <UInput
              v-model="form.email"
              type="email"
              autocomplete="email"
              class="w-full"
            />
          </UFormField>
          <UFormField
            :label="t('identityConsole.signIn.password')"
            required
          >
            <UInput
              v-model="form.password"
              type="password"
              autocomplete="current-password"
              class="w-full"
            />
          </UFormField>
          <UAlert
            v-if="errorMessage"
            color="error"
            variant="soft"
            :description="errorMessage"
          />
          <UButton
            type="submit"
            block
            :label="t('identityConsole.signIn.submit')"
            trailing-icon="i-lucide-arrow-right"
            :loading="pending"
          />
        </form>
      </UPageCard>
    </main>
  </div>
</template>

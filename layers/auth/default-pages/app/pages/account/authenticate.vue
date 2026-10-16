<script setup lang="ts">
import * as z from 'zod/v4'
import type { ComputedRef } from 'vue'
import type { FormSubmitEvent } from '@nuxt/ui'
import { useIdentityMutation } from '../../../../app/composables/useIdentityMutation'

definePageMeta({ layout: 'identity-auth' })

const brand = inject<ComputedRef<{
  presentation: { logoUrl?: string | null, accentColor?: string | null }
} | null>>('identity-auth-brand', computed(() => null))

type AccountContext = {
  application: {
    key: string
    name: string
    returnUrl: string
    authentication: { googleEnabled: boolean }
    sandboxEnabled: boolean
  }
  project: { mode: 'live' | 'sandbox' }
  entryAuthorized: boolean
  authenticated: boolean
}

const schema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(8, 'Enter your password.')
})
type Schema = z.output<typeof schema>

const route = useRoute()
const toast = useToast()
const mutate = useIdentityMutation()
const application = computed(() =>
  typeof route.query.application === 'string' ? route.query.application : ''
)
const intent = computed(() =>
  typeof route.query.intent === 'string' ? route.query.intent : undefined
)
const tab = computed<'profile' | 'security'>(() =>
  route.query.tab === 'security' ? 'security' : 'profile'
)
const pending = ref(false)
const googlePending = ref(false)
const fields = [
  {
    name: 'email',
    type: 'email' as const,
    label: 'Email',
    placeholder: 'you@example.com',
    required: true,
    autocomplete: 'email'
  },
  {
    name: 'password',
    type: 'password' as const,
    label: 'Password',
    placeholder: 'Enter your password',
    required: true,
    autocomplete: 'current-password'
  }
]
const providers = computed(() =>
  context.value?.application.authentication.googleEnabled
    ? [{
        label: 'Continue with Google',
        icon: 'i-simple-icons-google',
        loading: googlePending.value,
        disabled: context.value.application.sandboxEnabled,
        onClick: continueWithGoogle
      }]
    : []
)

const {
  data: context,
  error: contextError,
  pending: contextPending
} = await useFetch<AccountContext>('/api/hosted-account/context', {
  key: computed(() => `identity-hosted-account-authentication-context:${application.value}:${intent.value ?? ''}`),
  query: { application, intent },
  server: false
})

watch(
  context,
  async (value) => {
    if (!value) return
    if (!value.entryAuthorized) {
      await navigateTo(value.application.returnUrl, { external: true })
      return
    }
    if (value.authenticated) {
      await openAccountSettings()
    }
  },
  { immediate: true }
)

function message(error: unknown, fallback: string) {
  const candidate = error as {
    data?: { message?: string, statusMessage?: string }
    statusMessage?: string
  }
  return (
    candidate.data?.statusMessage
    ?? candidate.data?.message
    ?? candidate.statusMessage
    ?? fallback
  )
}

function openAccountSettings() {
  return navigateTo({
    path: '/account',
    query: { application: application.value, tab: tab.value }
  }, { replace: true })
}

async function signIn({ data }: FormSubmitEvent<Schema>) {
  if (pending.value || !context.value?.entryAuthorized) return
  pending.value = true
  try {
    await mutate('/api/hosted-account/login', {
      method: 'POST',
      body: { application: application.value, ...data }
    })
    // The account layout may have cached the unauthenticated context before
    // this page completed the login. Drop it before mounting the settings
    // page so it fetches the newly authenticated session.
    clearNuxtData('identity-hosted-account-context')
    await openAccountSettings()
  } catch (error) {
    toast.add({
      title: 'Unable to sign in',
      description: message(error, 'We could not sign you in.'),
      color: 'error'
    })
  } finally {
    pending.value = false
  }
}

async function continueWithGoogle() {
  if (googlePending.value || !context.value?.entryAuthorized || context.value.application.sandboxEnabled) return
  googlePending.value = true
  try {
    const result = await mutate<{ redirectUrl: string }>('/api/hosted-account/google', {
      method: 'POST',
      body: { application: application.value, tab: tab.value }
    })
    await navigateTo(result.redirectUrl, { external: true })
  } catch (error) {
    toast.add({
      title: 'Unable to continue with Google',
      description: message(error, 'We could not start Google sign-in.'),
      color: 'error'
    })
  } finally {
    googlePending.value = false
  }
}
</script>

<template>
  <div class="identity-auth-page">
    <div
      v-if="contextPending"
      class="identity-auth-form-shell identity-auth-loading-shell flex flex-col items-center gap-3 text-center"
      aria-busy="true"
      aria-live="polite"
      aria-label="Verifying request"
    >
      <USkeleton class="h-8 w-40" />
      <USkeleton class="h-4 w-64 max-w-full" />
      <USkeleton class="mt-3 h-10 w-full" />
      <USkeleton class="h-10 w-full" />
    </div>

    <div
      v-else-if="contextError"
      class="identity-auth-form-shell space-y-4"
    >
      <UAlert
        color="error"
        variant="subtle"
        title="We could not load account sign-in."
        description="Return to the application and open account settings again."
      />
    </div>

    <div
      v-else-if="context?.authenticated"
      class="identity-auth-form-shell identity-auth-loading-shell flex flex-col items-center gap-3 text-center"
      aria-live="polite"
    >
      <USkeleton class="h-8 w-40" />
      <USkeleton class="h-4 w-64 max-w-full" />
      <USkeleton class="mt-3 h-10 w-full" />
    </div>

    <UAuthForm
      v-else-if="context?.entryAuthorized && context.project.mode === 'live'"
      class="identity-auth-form-shell"
      :fields="fields"
      :schema="schema"
      :validate-on="['input']"
      :providers="providers"
      title="Sign in"
      description="Sign in to access your account settings."
      :submit="{ label: 'Sign in', loading: pending }"
      @submit="signIn"
    >
      <template #header>
        <IdentityAuthFormHeader
          title="Sign in"
          description="Sign in to access your account settings."
        />
      </template>
    </UAuthForm>

    <div
      v-else-if="context?.entryAuthorized"
      class="identity-auth-form-shell"
    >
      <UAlert
        color="neutral"
        variant="subtle"
        title="Account settings are unavailable"
        description="Temporary sandbox accounts do not have persistent account settings."
      />
    </div>

    <div
      v-else
      class="identity-auth-return-state"
      aria-live="polite"
      aria-label="Returning to the application"
    >
      <IdentityAuthBrandMark
        :logo-url="brand?.presentation.logoUrl"
        :accent-color="brand?.presentation.accentColor"
        size="form"
        class="animate-pulse"
      />
    </div>
  </div>
</template>

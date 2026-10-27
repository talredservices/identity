<script setup lang="ts">
import type { ComputedRef } from 'vue'
import * as z from 'zod/v4'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { IdentityAuthenticationContext } from '../../../shared/types/identity-auth'
import { useIdentityMutation } from '../../../app/composables/useIdentityMutation'

const route = useRoute()
const brand = inject<ComputedRef<{
  authentication: { termsUrl: string | null, privacyUrl: string | null }
} | null>>('identity-auth-brand', computed(() => null))
const config = useRuntimeConfig()
const toast = useToast()
const auth = useIdentityAuth()
const mutateIdentity = useIdentityMutation()
const hostedApplication = computed(() =>
  typeof route.query.application === 'string' ? route.query.application : ''
)
const hostedState = computed(() =>
  typeof route.query.state === 'string' ? route.query.state : ''
)
const hosted = computed(() =>
  Boolean(hostedApplication.value && hostedState.value)
)
const loginSchema = z.object({
  email: z.email('Enter a valid email address.'),
  password: z.string().min(8, 'Enter your password.')
})
type LoginSchema = z.output<typeof loginSchema>
const pending = ref(false)
const demoPending = ref(false)
const demoReady = ref(false)
const googlePending = ref(false)
const policyConsentOpen = ref(false)
const policyConsentAccepted = ref(false)
const policyConsentAction = ref<'demo' | 'google'>('demo')

const {
  data: experience,
  error: experienceError,
  pending: experienceFetchPending
} = useFetch(
  () => (hosted.value ? '/api/hosted-auth/context' : '/api/auth/context'),
  {
    query: computed(() =>
      hosted.value
        ? {
            application: hostedApplication.value
          }
        : {}
    ),
    server: false,
    lazy: true,
    default: () => null
  }
)
const experiencePending = computed(() =>
  experienceFetchPending.value || (!experience.value && !experienceError.value)
)

const primary = computed(() => experience.value?.primary ?? null)
const liveEnabled = computed(() => primary.value?.project.mode === 'live')
const demoContext = computed<IdentityAuthenticationContext | null>(() => {
  if (!hosted.value && !config.public.identityAuth.sandboxEnabled) return null
  if (primary.value?.project.mode === 'sandbox') return primary.value
  if (experience.value?.sandbox?.project.mode === 'sandbox')
    return experience.value.sandbox
  return null
})
const registrationEnabled = computed(
  () =>
    liveEnabled.value && primary.value?.project.registration_mode === 'public'
)
const hostedAuthentication = computed(() => {
  const context = experience.value
  return context && 'application' in context && context.application
    ? context.application.authentication
    : undefined
})
const googleEnabled = computed(
  () => hosted.value && hostedAuthentication.value?.googleEnabled === true
)
const demoOnly = computed(
  () => hosted.value && hostedAuthentication.value?.demoOnly === true
)
const termsRequired = computed(
  () => hosted.value && hostedAuthentication.value?.termsRequired === true
)
const fields = computed(() => [
  {
    name: 'email',
    type: 'email' as const,
    label: 'Email',
    placeholder: 'you@example.com',
    required: true,
    autocomplete: 'email',
    disabled: demoOnly.value
  },
  {
    name: 'password',
    type: 'password' as const,
    label: 'Password',
    placeholder: 'Enter your password',
    required: true,
    autocomplete: 'current-password',
    disabled: demoOnly.value
  }
])
const providers = computed(() =>
  googleEnabled.value
    ? [
        {
          label: 'Continue with Google',
          icon: 'i-simple-icons-google',
          loading: googlePending.value,
          disabled: demoOnly.value || Boolean(demoContext.value),
          onClick: requestGoogleSignIn
        }
      ]
    : []
)
const demoButtonLabel = computed(() => {
  if (demoReady.value) return 'Continue as demo'
  return demoPending.value ? 'Preparing demo…' : 'Create instant demo account'
})

function destination(): string {
  return identitySafeRedirect(
    route.query.redirect,
    config.public.identityAuth.loginRedirect
  )
}

async function submit({ data }: FormSubmitEvent<LoginSchema>) {
  if (demoOnly.value) return
  pending.value = true

  try {
    if (hosted.value) {
      const result = await mutateIdentity<{ redirectUrl: string }>(
        '/api/hosted-auth/login',
        {
          method: 'POST',
          body: {
            application: hostedApplication.value,
            state: hostedState.value,
            ...data
          }
        }
      )
      await navigateTo(result.redirectUrl, { external: true })
      return
    }

    await auth.login(data)
    await navigateTo(destination())
  } catch (error) {
    toast.add({
      title: 'Unable to sign in',
      description: identityLoginErrorMessage(error),
      color: 'error'
    })
  } finally {
    pending.value = false
  }
}

async function provisionDemo(termsAccepted = false) {
  if (demoPending.value || demoReady.value || !demoContext.value) return

  demoPending.value = true

  try {
    if (hosted.value) {
      const result = await mutateIdentity<{ redirectUrl: string }>(
        '/api/hosted-auth/sandbox',
        {
          method: 'POST',
          body: {
            application: hostedApplication.value,
            state: hostedState.value,
            termsAccepted
          }
        }
      )
      await navigateTo(result.redirectUrl, { external: true })
      return
    }

    await auth.createSandboxSession(demoContext.value.connection)
    demoReady.value = true
  } catch (error) {
    toast.add({
      title: 'Unable to prepare the demo',
      description: identityAuthErrorMessage(
        error,
        'We could not prepare the temporary demo account.'
      ),
      color: 'error'
    })
  } finally {
    demoPending.value = false
  }
}

async function handleDemoAction() {
  if (demoReady.value) {
    await navigateTo(destination())
    return
  }

  if (termsRequired.value) {
    policyConsentAction.value = 'demo'
    policyConsentAccepted.value = false
    policyConsentOpen.value = true
    return
  }

  await provisionDemo(false)
}

async function requestGoogleSignIn() {
  if (termsRequired.value) {
    policyConsentAction.value = 'google'
    policyConsentAccepted.value = false
    policyConsentOpen.value = true
    return
  }

  await continueWithGoogle(false)
}

async function acceptPolicyConsent() {
  if (!policyConsentAccepted.value) return
  policyConsentOpen.value = false
  if (policyConsentAction.value === 'demo') {
    await provisionDemo(true)
    return
  }

  await continueWithGoogle(true)
}

async function continueWithGoogle(termsAccepted: boolean) {
  if (!hosted.value) return
  googlePending.value = true
  try {
    const result = await mutateIdentity<{ redirectUrl: string }>(
      '/api/hosted-auth/google',
      {
        method: 'POST',
        body: {
          application: hostedApplication.value,
          state: hostedState.value,
          termsAccepted
        }
      }
    )
    await navigateTo(result.redirectUrl, { external: true })
  } catch (error) {
    toast.add({
      title: 'Unable to continue with Google',
      description: identityAuthErrorMessage(
        error,
        'We could not start Google sign-in.'
      ),
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
      v-if="experienceError"
      class="identity-auth-form-shell space-y-4"
    >
      <IdentityAuthFormHeader
        title="Sign in"
        description="We could not load this application's authentication settings."
        show-brand
      />
      <p class="identity-auth-error">
        Try refreshing the page or return to the application and start again.
      </p>
    </div>

    <div
      v-else-if="experiencePending"
      class="identity-auth-form-shell space-y-4"
    >
      <IdentityAuthFormHeader
        title="Sign in"
        show-brand
      />
      <div
        class="identity-auth-status"
        role="status"
        aria-live="polite"
      >
        Preparing your sign-in…
      </div>
    </div>

    <template v-else>
      <UAuthForm
        v-if="liveEnabled"
        class="identity-auth-form-shell"
        :fields="fields"
        :schema="loginSchema"
        :validate-on="['input']"
        :providers="providers"
        title="Sign in"
        description="Sign in to continue."
        :submit="{ label: 'Sign in', loading: pending, disabled: demoOnly }"
        @submit="submit"
      >
        <template #header>
          <IdentityAuthFormHeader
            title="Sign in"
          >
            <p class="identity-auth-form-header-link">
              <template v-if="registrationEnabled && !demoOnly">
                New here? <NuxtLink
                  :to="{
                    ...identityAuthPagePath('register', route.params.pageSet, hosted ? { application: hostedApplication, state: hostedState } : {})
                  }"
                  class="text-primary font-medium"
                >Create an account</NuxtLink><span>.</span>
              </template>
              <template v-else>
                Use your existing account to continue.
              </template>
            </p>
          </IdentityAuthFormHeader>
        </template>
        <template #password-hint>
          <NuxtLink
            v-if="!demoOnly"
            :to="{
              ...identityAuthPagePath('forgot-password', route.params.pageSet, hosted ? { application: hostedApplication, state: hostedState } : {})
            }"
            class="text-primary font-medium"
            tabindex="-1"
          >Forgot password?</NuxtLink>
        </template>
        <template #footer>
          <div class="grid gap-3">
            <UButton
              v-if="demoContext"
              block
              color="neutral"
              variant="outline"
              :loading="demoPending"
              :label="demoButtonLabel"
              @click="handleDemoAction"
            />
          </div>
        </template>
      </UAuthForm>

      <div
        v-else-if="demoContext"
        class="identity-auth-form-shell grid gap-3"
      >
        <IdentityAuthFormHeader
          title="Try the demo"
          description="Create a temporary account to explore this application."
        />
        <UButton
          block
          color="neutral"
          variant="outline"
          :loading="demoPending"
          :label="demoButtonLabel"
          @click="handleDemoAction"
        />
      </div>
    </template>

    <IdentityAttribution
      :terms-url="brand?.authentication.termsUrl"
      :privacy-url="brand?.authentication.privacyUrl"
    />

    <IdentityHostedPolicyConsentModal
      v-model:open="policyConsentOpen"
      v-model:accepted="policyConsentAccepted"
      :terms-url="hostedAuthentication?.termsUrl"
      :privacy-url="hostedAuthentication?.privacyUrl"
      :description="policyConsentAction === 'demo'
        ? 'Confirm the legal terms before creating a temporary demo account.'
        : 'Accept the application policies before continuing with Google.'"
      :confirm-label="policyConsentAction === 'demo'
        ? 'Accept and create demo account'
        : 'Accept and continue with Google'"
      :loading="demoPending || googlePending"
      @confirm="acceptPolicyConsent"
    />
  </div>
</template>

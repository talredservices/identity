<script setup lang="ts">
import * as z from 'zod/v4'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { IdentityAuthenticationExperience } from '../../../../shared/types/identity-auth'
import { useIdentityMutation } from '../../../../app/composables/useIdentityMutation'

definePageMeta({
  layout: 'identity-auth',
  middleware: 'identity-live-auth'
})

type ProfileStep = {
  username: string
  email: string
}

type PasswordStep = {
  password: string
  passwordConfirmation: string
}

const config = useRuntimeConfig()
const route = useRoute()
const toast = useToast()
const { register } = useIdentityAuth()
const mutateIdentity = useIdentityMutation()
const hostedApplication = computed(() => typeof route.query.application === 'string' ? route.query.application : '')
const hostedState = computed(() => typeof route.query.state === 'string' ? route.query.state : '')
const hosted = computed(() => Boolean(hostedApplication.value && hostedState.value))
const pending = ref(false)
const step = ref<'profile' | 'password'>('profile')
const registration = reactive<ProfileStep & PasswordStep>({
  username: '',
  email: '',
  password: '',
  passwordConfirmation: ''
})
const consentOpen = ref(false)
const consentAccepted = ref(false)
const consentAction = ref<'register' | 'google'>('register')
const { data: experience } = await useFetch<IdentityAuthenticationExperience & { application?: { authentication?: { demoOnly?: boolean, termsRequired?: boolean, termsUrl?: string | null, privacyUrl?: string | null } } }>(
  () => hosted.value ? '/api/hosted-auth/context' : '',
  { query: computed(() => hosted.value ? { application: hostedApplication.value } : {}), immediate: hosted.value }
)
const hostedAuthentication = computed(() => experience.value?.application?.authentication)
const demoOnly = computed(() => hosted.value && hostedAuthentication.value?.demoOnly === true)
const termsRequired = computed(() => hostedAuthentication.value?.termsRequired ?? false)
const consentDescription = computed(() => consentAction.value === 'google'
  ? 'Accept the application policies before continuing with Google.'
  : 'Confirm the legal terms before creating your account.')
const consentConfirmLabel = computed(() => consentAction.value === 'google'
  ? 'Accept and continue with Google'
  : 'Accept and create account')
const googleEnabled = computed(() => hosted.value && hostedAuthentication.value?.googleEnabled === true)
const googlePending = ref(false)
const profileSchema = z.object({
  username: z.string().trim().min(2, 'Enter your name.').max(100),
  email: z.email('Enter a valid email address.')
})
const passwordSchema = z.object({
  password: z.string().min(12, 'Use at least 12 characters.'),
  passwordConfirmation: z.string().min(12, 'Confirm your password.')
}).refine(data => data.password === data.passwordConfirmation, {
  message: 'The password confirmation does not match.',
  path: ['passwordConfirmation']
})
const profileFields = computed(() => [
  { name: 'username', type: 'text' as const, label: 'Name', placeholder: 'Your name', required: true, autocomplete: 'name', defaultValue: registration.username, disabled: demoOnly.value },
  { name: 'email', type: 'email' as const, label: 'Email', placeholder: 'you@example.com', required: true, autocomplete: 'email', defaultValue: registration.email, disabled: demoOnly.value }
])
const passwordFields = computed(() => [
  { name: 'password', type: 'password' as const, label: 'Password', placeholder: 'At least 12 characters', required: true, autocomplete: 'new-password', defaultValue: registration.password, disabled: demoOnly.value },
  { name: 'passwordConfirmation', type: 'password' as const, label: 'Confirm password', placeholder: 'Repeat your password', required: true, autocomplete: 'new-password', defaultValue: registration.passwordConfirmation, disabled: demoOnly.value }
])
const providers = computed(() => googleEnabled.value
  ? [{
      label: 'Continue with Google',
      icon: 'i-simple-icons-google',
      loading: googlePending.value,
      disabled: demoOnly.value || Boolean(experience.value?.sandbox),
      onClick: requestGoogleSignIn
    }]
  : [])

function continueToPassword({ data }: FormSubmitEvent<ProfileStep>) {
  if (demoOnly.value) return
  registration.username = data.username
  registration.email = data.email
  step.value = 'password'
}

function returnToProfile() {
  step.value = 'profile'
}

async function requestAccountCreation({ data }: FormSubmitEvent<PasswordStep>) {
  if (demoOnly.value) return
  registration.password = data.password
  registration.passwordConfirmation = data.passwordConfirmation
  if (termsRequired.value) {
    consentAction.value = 'register'
    consentAccepted.value = false
    consentOpen.value = true
    return
  }

  await createAccount(false)
}

async function acceptConsent() {
  if (!consentAccepted.value) return
  consentOpen.value = false
  if (consentAction.value === 'google') {
    await continueWithGoogle(true)
    return
  }

  await createAccount(true)
}

async function createAccount(termsAccepted: boolean) {
  if (demoOnly.value) return
  pending.value = true

  try {
    const data = { ...registration, termsAccepted }
    if (hosted.value) {
      const result = await mutateIdentity<{ redirectUrl?: string }>('/api/hosted-auth/register', {
        method: 'POST',
        body: {
          application: hostedApplication.value,
          state: hostedState.value,
          ...data
        }
      })
      if (result.redirectUrl) {
        await navigateTo(result.redirectUrl, { external: true })
        return
      }
      await navigateTo({
        ...identityAuthPagePath('verify-email', route.params.pageSet, { application: hostedApplication.value, state: hostedState.value })
      })
      return
    }

    await register(data)
    await navigateTo(identitySafeRedirect(
      config.public.identityAuth.registerRedirect,
      '/auth/verify-email'
    ))
  } catch (error) {
    toast.add({
      title: 'Unable to create your account',
      description: identityAuthErrorMessage(error, 'We could not create your account.'),
      color: 'error'
    })
  } finally {
    pending.value = false
  }
}

function requestGoogleSignIn() {
  if (termsRequired.value) {
    consentAction.value = 'google'
    consentAccepted.value = false
    consentOpen.value = true
    return
  }

  void continueWithGoogle(false)
}

async function continueWithGoogle(termsAccepted: boolean) {
  if (!hosted.value || demoOnly.value) return
  googlePending.value = true
  try {
    const result = await mutateIdentity<{ redirectUrl: string }>('/api/hosted-auth/google', {
      method: 'POST',
      body: { application: hostedApplication.value, state: hostedState.value, termsAccepted }
    })
    await navigateTo(result.redirectUrl, { external: true })
  } catch (error) {
    toast.add({
      title: 'Unable to continue with Google',
      description: identityAuthErrorMessage(error, 'We could not start Google sign-in.'),
      color: 'error'
    })
  } finally {
    googlePending.value = false
  }
}
</script>

<template>
  <div class="identity-auth-page">
    <Transition
      name="identity-auth-step"
      mode="out-in"
    >
      <UAuthForm
        v-if="step === 'profile'"
        key="profile"
        class="identity-auth-form-shell"
        :fields="profileFields"
        :schema="profileSchema"
        :validate-on="['input']"
        :providers="providers"
        title="Create account"
        description="Create an account for this application."
        :submit="{ label: 'Continue', disabled: demoOnly }"
        @submit="continueToPassword"
      >
        <template #header>
          <IdentityAuthFormHeader
            title="Create account"
          >
            <p class="identity-auth-form-header-link">
              Already have an account? <NuxtLink
                :to="identityAuthPagePath('login', route.params.pageSet, hosted ? { application: hostedApplication, state: hostedState } : {})"
                class="text-primary font-medium"
              >Sign in</NuxtLink><span>.</span>
            </p>
          </IdentityAuthFormHeader>
        </template>
      </UAuthForm>

      <UAuthForm
        v-else
        key="password"
        class="identity-auth-form-shell"
        :fields="passwordFields"
        :schema="passwordSchema"
        :validate-on="['input']"
        title="Secure your account"
        description="Choose a password to finish creating your account."
        :submit="{ label: 'Create account', loading: pending, disabled: demoOnly }"
        @submit="requestAccountCreation"
      >
        <template #header>
          <IdentityAuthFormHeader
            title="Secure your account"
            description="Choose a password to finish creating your account."
          />
        </template>
        <template #footer>
          <button
            type="button"
            class="text-primary font-medium"
            :disabled="pending"
            @click="returnToProfile"
          >
            Back to name and email
          </button>
        </template>
      </UAuthForm>
    </Transition>

    <IdentityHostedPolicyConsentModal
      v-model:open="consentOpen"
      v-model:accepted="consentAccepted"
      :terms-url="hostedAuthentication?.termsUrl"
      :privacy-url="hostedAuthentication?.privacyUrl"
      :description="consentDescription"
      :confirm-label="consentConfirmLabel"
      :loading="pending || googlePending"
      @confirm="acceptConsent"
    />
  </div>
</template>

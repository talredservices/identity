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
const { data: experience } = await useFetch<IdentityAuthenticationExperience & { application?: { authentication?: { termsRequired?: boolean, termsUrl?: string | null, privacyUrl?: string | null } } }>(
  () => hosted.value ? '/api/hosted-auth/context' : '',
  { query: computed(() => hosted.value ? { application: hostedApplication.value } : {}), immediate: hosted }
)
const hostedAuthentication = computed(() => experience.value?.application?.authentication)
const consentLabel = computed(() => hostedAuthentication.value?.privacyUrl
  ? 'I have read and agree to the Terms of Service and Privacy Policy.'
  : 'I have read and agree to the Terms of Service.')
const termsRequired = computed(() => hostedAuthentication.value?.termsRequired ?? false)
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
  { name: 'username', type: 'text' as const, label: 'Name', placeholder: 'Your name', required: true, autocomplete: 'name', defaultValue: registration.username },
  { name: 'email', type: 'email' as const, label: 'Email', placeholder: 'you@example.com', required: true, autocomplete: 'email', defaultValue: registration.email }
])
const passwordFields = computed(() => [
  { name: 'password', type: 'password' as const, label: 'Password', placeholder: 'At least 12 characters', required: true, autocomplete: 'new-password', defaultValue: registration.password },
  { name: 'passwordConfirmation', type: 'password' as const, label: 'Confirm password', placeholder: 'Repeat your password', required: true, autocomplete: 'new-password', defaultValue: registration.passwordConfirmation }
])
const providers = computed(() => googleEnabled.value
  ? [{
      label: 'Continue with Google',
      icon: 'i-simple-icons-google',
      loading: googlePending.value,
      disabled: Boolean(experience.value?.sandbox),
      onClick: continueWithGoogle
    }]
  : [])

function continueToPassword({ data }: FormSubmitEvent<ProfileStep>) {
  registration.username = data.username
  registration.email = data.email
  step.value = 'password'
}

function returnToProfile() {
  step.value = 'profile'
}

async function requestAccountCreation({ data }: FormSubmitEvent<PasswordStep>) {
  registration.password = data.password
  registration.passwordConfirmation = data.passwordConfirmation
  if (termsRequired.value) {
    consentAccepted.value = false
    consentOpen.value = true
    return
  }

  await createAccount(false)
}

async function acceptConsent() {
  if (!consentAccepted.value) return
  consentOpen.value = false
  await createAccount(true)
}

async function createAccount(termsAccepted: boolean) {
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

async function continueWithGoogle() {
  if (!hosted.value) return
  googlePending.value = true
  try {
    const result = await mutateIdentity<{ redirectUrl: string }>('/api/hosted-auth/google', {
      method: 'POST',
      body: { application: hostedApplication.value, state: hostedState.value }
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
        :submit="{ label: 'Continue' }"
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
        :submit="{ label: 'Create account', loading: pending }"
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

    <UModal
      v-model:open="consentOpen"
      title="Review and accept"
      description="Confirm the legal terms before creating your account."
    >
      <template #body>
        <div class="space-y-5 sm:space-y-6">
          <div class="grid gap-3 sm:grid-cols-2">
            <a
              v-if="hostedAuthentication?.termsUrl"
              :href="hostedAuthentication.termsUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="group flex min-w-0 items-center gap-3 rounded-xl border border-default bg-elevated p-4 transition-colors hover:border-primary"
            >
              <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-primary">
                <UIcon
                  name="i-lucide-file-text"
                  class="size-5"
                  aria-hidden="true"
                />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-highlighted">Terms of Service</span>
                <span class="mt-1 block text-xs text-muted">Open policy</span>
              </span>
              <UIcon
                name="i-lucide-arrow-up-right"
                class="size-4 shrink-0 text-muted transition-colors group-hover:text-primary"
                aria-hidden="true"
              />
            </a>
            <a
              v-if="hostedAuthentication?.privacyUrl"
              :href="hostedAuthentication.privacyUrl"
              target="_blank"
              rel="noopener noreferrer"
              class="group flex min-w-0 items-center gap-3 rounded-xl border border-default bg-elevated p-4 transition-colors hover:border-primary"
            >
              <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-primary">
                <UIcon
                  name="i-lucide-lock-keyhole"
                  class="size-5"
                  aria-hidden="true"
                />
              </span>
              <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-highlighted">Privacy Policy</span>
                <span class="mt-1 block text-xs text-muted">Open policy</span>
              </span>
              <UIcon
                name="i-lucide-arrow-up-right"
                class="size-4 shrink-0 text-muted transition-colors group-hover:text-primary"
                aria-hidden="true"
              />
            </a>
          </div>

          <div class="rounded-xl border border-default bg-muted p-4 sm:p-5">
            <UCheckbox
              v-model="consentAccepted"
              :label="consentLabel"
            />
          </div>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end sm:gap-3">
          <UButton
            label="Cancel"
            color="neutral"
            variant="outline"
            class="w-full justify-center sm:w-auto"
            @click="consentOpen = false"
          />
          <UButton
            label="Accept and create account"
            icon="i-lucide-check"
            :disabled="!consentAccepted"
            :loading="pending"
            class="w-full justify-center sm:w-auto"
            @click="acceptConsent"
          />
        </div>
      </template>
    </UModal>
  </div>
</template>

<script setup lang="ts">
import * as z from 'zod/v4'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { IdentityHostedApplicationMetadata } from '../../../../shared/types/identity-auth'
import { useIdentityMutation } from '../../../../app/composables/useIdentityMutation'

definePageMeta({
  layout: 'identity-auth',
  middleware: 'identity-live-auth'
})

const route = useRoute()
const toast = useToast()
const { resetPassword } = useIdentityAuth()
const mutateIdentity = useIdentityMutation()
const hostedClientId = computed(() => typeof route.query.client_id === 'string' ? route.query.client_id : '')
const hostedApplication = computed(() => typeof route.query.application === 'string' ? route.query.application : '')
const hostedState = computed(() => typeof route.query.state === 'string' ? route.query.state : '')
const { data: hostedMetadata } = await useFetch<{ application: IdentityHostedApplicationMetadata }>(
  '/api/hosted-auth/application',
  {
    query: computed(() => ({ clientId: hostedClientId.value })),
    immediate: z.uuid().safeParse(hostedClientId.value).success
  }
)
const demoOnly = computed(() => hostedMetadata.value?.application.authentication.demoOnly === true)
const hasTokenFromLink = computed(() => typeof route.query.token === 'string' && route.query.token.length >= 64 && route.query.token.length <= 512)
const hasEmailFromLink = computed(() => typeof route.query.email === 'string' && z.email().max(254).safeParse(route.query.email).success)
const signInPath = computed(() => identityAuthPagePath('login', route.params.pageSet,
  hostedApplication.value && hostedState.value
    ? { application: hostedApplication.value, state: hostedState.value }
    : {}
))
const applicationSignInUrl = computed(() => {
  const returnUrl = hostedMetadata.value?.application.returnUrl
  if (!returnUrl) return ''

  try {
    const target = new URL(returnUrl)
    if (!['http:', 'https:'].includes(target.protocol)) return ''
    target.pathname = `${target.pathname.replace(/\/$/, '')}/auth/login`
    target.search = ''
    target.hash = ''
    return target.toString()
  } catch {
    return ''
  }
})
const signInTarget = computed(() => hostedApplication.value && hostedState.value
  ? signInPath.value
  : applicationSignInUrl.value || applicationUrl.value || signInPath.value
)
const externalSignIn = computed(() => typeof signInTarget.value === 'string')
const schema = z.object({
  email: z.email('Enter a valid email address.').max(254, 'Enter a valid email address.'),
  token: z.preprocess(value => hasTokenFromLink.value ? route.query.token : value, z.string().min(64, 'Open the reset link from your email.').max(512, 'This reset link is invalid.')),
  password: z.string().min(12, 'Use at least 12 characters.'),
  passwordConfirmation: z.string().min(12, 'Confirm your password.')
}).refine(data => data.password === data.passwordConfirmation, {
  message: 'The password confirmation does not match.',
  path: ['passwordConfirmation']
})
type ResetPasswordSchema = z.output<typeof schema>
const fields = computed(() => [
  { name: 'email', type: 'email' as const, label: 'Email', placeholder: 'you@example.com', required: true, readonly: hasEmailFromLink.value, autofocus: !hasEmailFromLink.value, maxlength: 254, autocomplete: 'email', defaultValue: typeof route.query.email === 'string' ? route.query.email : '', disabled: demoOnly.value },
  ...(hasTokenFromLink.value ? [] : [{ name: 'token', type: 'text' as const, label: 'Reset token', description: 'Paste the token from your email.', placeholder: 'Paste the token from your email', required: true, maxlength: 512, autocomplete: 'one-time-code', defaultValue: typeof route.query.token === 'string' ? route.query.token : '', disabled: demoOnly.value }]),
  { name: 'password', type: 'password' as const, label: 'New password', description: 'Use at least 12 characters.', autofocus: hasEmailFromLink.value && hasTokenFromLink.value, placeholder: 'At least 12 characters', required: true, autocomplete: 'new-password', disabled: demoOnly.value },
  { name: 'passwordConfirmation', type: 'password' as const, label: 'Confirm password', placeholder: 'Repeat your password', required: true, autocomplete: 'new-password', disabled: demoOnly.value }
])
const pending = ref(false)
const successMessage = ref('')
const errorMessage = ref('')
const applicationUrl = ref('')

async function submit({ data }: FormSubmitEvent<ResetPasswordSchema>) {
  if (pending.value || successMessage.value || demoOnly.value) return
  pending.value = true
  errorMessage.value = ''

  try {
    if (hostedClientId.value) {
      const result = await mutateIdentity<{ applicationUrl?: string }>('/api/hosted-auth/password/reset', {
        method: 'POST',
        body: { clientId: hostedClientId.value, ...data }
      })
      applicationUrl.value = result.applicationUrl ?? ''
    } else {
      await resetPassword(data)
    }
    successMessage.value = 'Your password has been reset. You can now sign in.'
  } catch (error) {
    errorMessage.value = identityAuthErrorMessage(error, 'We could not reset your password. Request a new link if this one has expired.')
    toast.add({
      title: 'Unable to reset your password',
      description: errorMessage.value,
      color: 'error'
    })
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="identity-auth-form-stack">
    <div
      v-if="successMessage"
      class="identity-auth-form-shell space-y-6"
    >
      <IdentityAuthFormHeader
        title="Password updated"
        description="Sign in with your new password to continue."
      />
      <UAlert
        class="identity-auth-success"
        color="success"
        variant="soft"
        icon="i-lucide-check-circle-2"
        title="Password reset complete"
        :description="successMessage"
        role="status"
        aria-live="polite"
        :ui="{ title: 'text-inherit font-semibold', description: 'text-inherit', icon: 'text-inherit' }"
      />
      <UButton
        :to="signInTarget"
        :external="externalSignIn"
        block
        trailing-icon="i-lucide-arrow-right"
      >
        Sign in
      </UButton>
    </div>
    <UAuthForm
      v-else
      class="identity-auth-form-shell"
      :fields="fields"
      :schema="schema"
      :validate-on="['input']"
      title="Choose a new password"
      :description="demoOnly ? 'Password reset is disabled for this application. Use demo sign-in to continue.' : 'Set a new password to secure your account.'"
      :submit="{ label: 'Reset password', loading: pending, disabled: demoOnly }"
      @submit="submit"
    >
      <template #header>
        <IdentityAuthFormHeader
          title="Choose a new password"
          :description="demoOnly ? 'Password reset is disabled for this application. Use demo sign-in to continue.' : 'Set a new password to secure your account.'"
        />
      </template>
      <template #validation>
        <UAlert
          v-if="errorMessage"
          color="error"
          title="Password not changed"
          :description="errorMessage"
          role="alert"
        />
      </template>
      <template #footer>
        <NuxtLink
          :to="signInTarget"
          :external="externalSignIn"
          class="identity-auth-return-link"
        >Back to sign in</NuxtLink>
      </template>
    </UAuthForm>
  </div>
</template>

<script setup lang="ts">
import * as z from 'zod/v4'
import type { FormSubmitEvent } from '@nuxt/ui'
import { useIdentityMutation } from '../../../../app/composables/useIdentityMutation'

definePageMeta({
  layout: 'identity-auth',
  middleware: 'identity-live-auth'
})

const route = useRoute()
const toast = useToast()
const { forgotPassword } = useIdentityAuth()
const mutateIdentity = useIdentityMutation()
const hostedApplication = computed(() => typeof route.query.application === 'string' ? route.query.application : '')
const schema = z.object({ email: z.email('Enter a valid email address.') })
type ForgotPasswordSchema = z.output<typeof schema>
const fields = [{ name: 'email', type: 'email' as const, label: 'Email', placeholder: 'you@example.com', required: true, autocomplete: 'email' }]
const pending = ref(false)
const successMessage = ref('')
const errorMessage = ref('')

async function submit({ data }: FormSubmitEvent<ForgotPasswordSchema>) {
  if (pending.value) return
  pending.value = true
  successMessage.value = ''
  errorMessage.value = ''

  try {
    if (hostedApplication.value) {
      await mutateIdentity('/api/hosted-auth/password/forgot', {
        method: 'POST',
        body: { application: hostedApplication.value, email: data.email }
      })
    } else {
      await forgotPassword(data.email)
    }
    successMessage.value = 'If that account exists, password reset instructions have been sent.'
  } catch (error) {
    errorMessage.value = identityAuthErrorMessage(error, 'We could not request a password reset. Please try again.')
    toast.add({
      title: 'Unable to request a password reset',
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
    <UAlert
      v-if="errorMessage"
      color="error"
      title="Password reset not requested"
      :description="errorMessage"
      role="alert"
    />
    <UAlert
      v-if="successMessage"
      color="success"
      variant="soft"
      icon="i-lucide-mail-check"
      title="Check your email"
      description="If that account exists, password reset instructions have been sent. Check your spam folder if you do not see the email soon."
      role="status"
      aria-live="polite"
      class="identity-auth-success"
      :ui="{ title: 'text-inherit font-semibold', description: 'text-inherit', icon: 'text-inherit' }"
    />

    <UAuthForm
      class="identity-auth-form-shell"
      :fields="fields"
      :schema="schema"
      :validate-on="['input']"
      title="Reset your password"
      description="Enter your email address and we will send the reset instructions."
      :submit="{ label: 'Send reset instructions', loading: pending }"
      @submit="submit"
    >
      <template #header>
        <IdentityAuthFormHeader
          title="Reset your password"
          description="Enter your email address and we will send the reset instructions."
        />
      </template>
      <template #footer>
        <NuxtLink :to="{ ...identityAuthPagePath('login', route.params.pageSet, hostedApplication ? { application: hostedApplication, state: route.query.state } : {}) }">
          Return to sign in
        </NuxtLink>
      </template>
    </UAuthForm>
  </div>
</template>

<script setup lang="ts">
import * as z from 'zod/v4'
import type { FormSubmitEvent } from '@nuxt/ui'
import { useIdentityMutation } from '../../../../app/composables/useIdentityMutation'

definePageMeta({
  layout: 'identity-auth',
  middleware: ['identity-live-auth']
})

const config = useRuntimeConfig()
const route = useRoute()
const toast = useToast()
const { verifyEmail, resendVerification, fetch } = useIdentityAuth()
const mutateIdentity = useIdentityMutation()
const hosted = computed(() => typeof route.query.application === 'string')
const expiredHostedFlow = ref(false)
const hostedLoginLocation = computed(() => ({
  ...identityAuthPagePath('login', route.params.pageSet, {
    application: route.query.application,
    ...(typeof route.query.state === 'string' ? { state: route.query.state } : {})
  })
}))
if (hosted.value) {
  try {
    await $fetch('/api/hosted-auth/flow')
  } catch (error) {
    const candidate = error as { status?: number, statusCode?: number }
    if (candidate.status !== 401 && candidate.statusCode !== 401) throw error
    expiredHostedFlow.value = true
  }
} else {
  const session = useUserSession()
  if (!session.loggedIn.value) await session.fetch()
  if (!session.loggedIn.value) {
    await navigateTo({ path: '/auth/login', query: { redirect: route.fullPath } })
  }
}
const schema = z.object({ code: z.string().regex(/^\d{6}$/, 'Enter the six-digit verification code.') })
type VerificationSchema = z.output<typeof schema>
const fields = [{ name: 'code', type: 'text' as const, label: 'Verification code', placeholder: '123456', required: true, autocomplete: 'one-time-code', inputmode: 'numeric', maxlength: 6 }]
const pending = ref(false)
const resending = ref(false)
const successMessage = ref('')

async function submit({ data }: FormSubmitEvent<VerificationSchema>) {
  pending.value = true
  successMessage.value = ''

  try {
    if (hosted.value) {
      const result = await mutateIdentity<{ redirectUrl: string }>('/api/hosted-auth/email/verification', {
        method: 'POST',
        body: { code: data.code }
      })
      await navigateTo(result.redirectUrl, { external: true })
      return
    }

    await verifyEmail(data.code)
    await fetch()
    successMessage.value = 'Your email address is verified.'
    await navigateTo(identitySafeRedirect(
      config.public.identityAuth.loginRedirect,
      '/'
    ))
  } catch (error) {
    toast.add({
      title: 'Unable to verify your email',
      description: identityAuthErrorMessage(
        error,
        'We could not verify that code.'
      ),
      color: 'error'
    })
  } finally {
    pending.value = false
  }
}

async function resend() {
  resending.value = true

  try {
    if (hosted.value) {
      await mutateIdentity('/api/hosted-auth/email/resend', { method: 'POST' })
    } else {
      await resendVerification()
    }
    successMessage.value = 'A new verification code has been sent.'
  } catch (error) {
    toast.add({
      title: 'Unable to send a verification code',
      description: identityAuthErrorMessage(
        error,
        'We could not send a new verification code.'
      ),
      color: 'error'
    })
  } finally {
    resending.value = false
  }
}
</script>

<template>
  <section
    v-if="expiredHostedFlow"
    class="identity-auth-form-shell space-y-5"
  >
    <IdentityAuthFormHeader
      title="Verification session expired"
      description="For your security, email verification must be completed shortly after signing in."
    />
    <UAlert
      color="neutral"
      variant="subtle"
      title="Start again to receive a new verification code."
    />
    <UButton
      :to="hostedLoginLocation"
      block
      label="Start again"
    />
  </section>

  <UAuthForm
    v-else
    class="identity-auth-form-shell"
    :fields="fields"
    :schema="schema"
    :validate-on="['input']"
    title="Verify your email"
    description="Enter the six-digit code sent to your email address."
    :submit="{ label: 'Verify email', loading: pending }"
    @submit="submit"
  >
    <template #header>
      <IdentityAuthFormHeader
        title="Verify your email"
        description="Enter the six-digit code sent to your email address."
      />
    </template>
    <template #validation>
      <p
        v-if="successMessage"
        class="identity-auth-success"
      >
        {{ successMessage }}
      </p>
    </template>
    <template #submit="{ loading }">
      <div class="grid gap-3">
        <UButton
          type="submit"
          block
          label="Verify email"
          :loading="loading"
        />
        <UButton
          type="button"
          block
          color="neutral"
          variant="outline"
          label="Send a new code"
          :loading="resending"
          @click="resend"
        />
      </div>
    </template>
  </UAuthForm>
</template>

<script setup lang="ts">
import LoginPage from '../../../components/IdentityHostedLoginPage.vue'
import RegisterPage from '../register.vue'
import ForgotPasswordPage from '../forgot-password.vue'
import ResetPasswordPage from '../reset-password.vue'
import VerifyEmailPage from '../verify-email.vue'

definePageMeta({
  layout: 'identity-auth',
  middleware: 'identity-live-auth'
})

const route = useRoute()
const pages = {
  'login': LoginPage,
  'register': RegisterPage,
  'forgot-password': ForgotPasswordPage,
  'reset-password': ResetPasswordPage,
  'verify-email': VerifyEmailPage
} as const
const screen = computed(() => typeof route.params.screen === 'string' ? route.params.screen : '')
const page = computed(() => pages[screen.value as keyof typeof pages])

if (!page.value) {
  throw createError({ statusCode: 404, statusMessage: 'Authentication page not found' })
}
</script>

<template>
  <component :is="page" />
</template>

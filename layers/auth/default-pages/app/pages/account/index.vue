<script setup lang="ts">
import type { ComputedRef } from 'vue'
import type { NavigationMenuItem } from '@nuxt/ui'
import { useIdentityMutation } from '../../../../app/composables/useIdentityMutation'

definePageMeta({
  layout: 'identity-account'
})

const brand = inject<ComputedRef<{ presentation: { logoUrl?: string | null, accentColor?: string | null } } | null>>(
  'identity-account-brand',
  computed(() => null)
)

type AccountContext = {
  application: { key: string, name: string, returnUrl: string }
  project: { mode: 'live' | 'sandbox' }
  entryAuthorized: boolean
  authenticated: boolean
  authenticationMethod: 'password' | 'google' | null
  user: { email: string, username: string } | null
}

type AccountProfile = {
  id: string
  email: string
  username?: string
  name?: string
  avatar_url?: string | null
}

const route = useRoute()
const mutate = useIdentityMutation()
const application = computed(() =>
  typeof route.query.application === 'string' ? route.query.application : ''
)

// The account portal is entered from a hosted application. Without that
// application key it has no client context with which to read or change an
// Identity account.
if (!application.value) {
  await navigateTo('/auth/complete', { replace: true })
}

const tab = computed<'profile' | 'security'>(() =>
  route.query.tab === 'security' ? 'security' : 'profile'
)
const navigation = computed<NavigationMenuItem[][]>(() => [
  [
    {
      label: 'Account',
      icon: 'i-lucide-user-round',
      to: {
        path: '/account',
        query: { application: application.value, tab: 'profile' }
      },
      active: tab.value === 'profile'
    },
    {
      label: 'Security',
      icon: 'i-lucide-shield-check',
      to: {
        path: '/account',
        query: { application: application.value, tab: 'security' }
      },
      active: tab.value === 'security'
    }
  ]
])
const pending = ref(false)
const loadingAccount = ref(false)
const errorMessage = ref('')
const accountErrorMessage = ref('')
const successMessage = ref('')
const profile = reactive({ username: '', email: '', avatarUrl: '' })
const password = reactive({ current: '', next: '', confirmation: '' })
const deleteConfirmation = ref('')
const passwordUnavailable = computed(
  () => context.value?.authenticationMethod === 'google'
)

const {
  data: context,
  error: contextError,
  pending: contextPending,
  refresh: refreshContext
} = await useFetch<AccountContext>('/api/hosted-account/context', {
  key: computed(() => `identity-hosted-account-context:${application.value}`),
  query: { application },
  server: false
})

watch(
  context,
  async (value) => {
    if (value && !value.entryAuthorized) {
      await navigateTo(value.application.returnUrl, { external: true })
      return
    }
    if (value && !value.authenticated) {
      await navigateTo({
        path: '/account/authenticate',
        query: { application: application.value, tab: tab.value }
      }, { replace: true })
      return
    }
    if (value?.authenticated) {
      await loadAccount()
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

function statusCode(error: unknown): number | undefined {
  const candidate = error as {
    status?: number
    statusCode?: number
    response?: { status?: number }
  }
  return candidate.status ?? candidate.statusCode ?? candidate.response?.status
}

async function loadAccount() {
  if (!context.value?.authenticated || !application.value) return
  loadingAccount.value = true
  accountErrorMessage.value = ''
  try {
    const account = await $fetch<AccountProfile>(
      '/api/hosted-account/profile',
      {
        query: { application: application.value }
      }
    )
    profile.username = account.username ?? account.name ?? ''
    profile.email = account.email
    profile.avatarUrl = account.avatar_url ?? ''
  } catch (error) {
    // A token can be revoked after context has loaded. Refreshing context
    // changes the view to confirmation rather than flashing a false error.
    if (statusCode(error) === 401) {
      await refreshContext()
      return
    }
    accountErrorMessage.value = message(
      error,
      'We could not load your Identity account.'
    )
  } finally {
    loadingAccount.value = false
  }
}

async function saveProfile() {
  pending.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const updatedProfile = await mutate<AccountProfile>('/api/hosted-account/profile', {
      method: 'PATCH',
      body: {
        application: application.value,
        username: profile.username,
        email: profile.email,
        avatar_url: profile.avatarUrl || null
      }
    })
    profile.username = updatedProfile.username ?? updatedProfile.name ?? profile.username
    profile.email = updatedProfile.email ?? profile.email
    profile.avatarUrl = updatedProfile.avatar_url ?? profile.avatarUrl
    if (context.value?.user) {
      context.value.user.username = profile.username
      context.value.user.email = profile.email
    }
    successMessage.value = 'Your profile was updated.'
  } catch (error) {
    errorMessage.value = message(error, 'We could not update your profile.')
  } finally {
    pending.value = false
  }
}

async function changePassword() {
  if (passwordUnavailable.value) return
  if (password.next !== password.confirmation) {
    errorMessage.value = 'The password confirmation does not match.'
    return
  }
  pending.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    await mutate('/api/hosted-account/password', {
      method: 'PATCH',
      body: {
        application: application.value,
        current_password: password.current,
        password: password.next,
        password_confirmation: password.confirmation
      }
    })
    Object.assign(password, { current: '', next: '', confirmation: '' })
    successMessage.value
      = 'Your password was changed. Other sessions were revoked.'
    await loadAccount()
  } catch (error) {
    errorMessage.value = message(error, 'We could not change your password.')
  } finally {
    pending.value = false
  }
}

async function exportAccount() {
  pending.value = true
  errorMessage.value = ''
  try {
    const data = await $fetch<Record<string, unknown>>(
      '/api/hosted-account/export',
      {
        query: { application: application.value }
      }
    )
    const url = URL.createObjectURL(
      new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
    )
    const link = document.createElement('a')
    link.href = url
    link.download = `identity-account-${new Date().toISOString().slice(0, 10)}.json`
    link.click()
    URL.revokeObjectURL(url)
  } catch (error) {
    errorMessage.value = message(
      error,
      'We could not export your account data.'
    )
  } finally {
    pending.value = false
  }
}

async function deleteAccount() {
  if (deleteConfirmation.value !== 'DELETE') return
  pending.value = true
  errorMessage.value = ''
  try {
    await mutate('/api/hosted-account', {
      method: 'DELETE',
      body: { application: application.value, confirmation: 'DELETE' }
    })
    await navigateTo(context.value?.application.returnUrl ?? '/', {
      external: true
    })
  } catch (error) {
    errorMessage.value = message(error, 'We could not delete your account.')
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <main class="flex-1">
    <UContainer class="w-full py-6 sm:py-8">
      <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
        <UAlert
          v-if="contextError"
          color="error"
          variant="subtle"
          title="We could not load this account."
        />

        <div
          v-else-if="contextPending || loadingAccount || !context"
          class="identity-account-loading-state"
          aria-busy="true"
          aria-label="Loading account settings"
        >
          <IdentityAuthBrandMark
            :logo-url="brand?.presentation.logoUrl"
            :accent-color="brand?.presentation.accentColor"
            size="form"
            class="animate-pulse"
          />
        </div>

        <template v-else-if="context.authenticated">
          <div class="grid gap-6 lg:grid-cols-[12rem_minmax(0,1fr)]">
            <IdentitySettingsNavigation
              :items="navigation"
              label="Account settings"
            />

            <section class="min-w-0 space-y-6">
              <UAlert
                v-if="accountErrorMessage"
                color="error"
                variant="subtle"
                :title="accountErrorMessage"
              />
              <UAlert
                v-if="successMessage"
                color="success"
                variant="subtle"
                :title="successMessage"
              />

              <UPageCard
                v-if="tab === 'profile'"
                title="Profile details"
                description="Keep your public account identity up to date."
                variant="subtle"
                :ui="{ container: 'gap-6 p-5 sm:p-6' }"
              >
                <form
                  class="space-y-6"
                  @submit.prevent="saveProfile"
                >
                  <div class="grid gap-5 md:grid-cols-2">
                    <UFormField
                      label="Username"
                      required
                    >
                      <UInput
                        v-model="profile.username"
                        class="w-full"
                      />
                    </UFormField>
                    <UFormField
                      label="Email"
                      required
                    >
                      <UInput
                        v-model="profile.email"
                        type="email"
                        class="w-full"
                      />
                    </UFormField>
                    <UFormField
                      label="Avatar URL"
                      class="md:col-span-2"
                    >
                      <UInput
                        v-model="profile.avatarUrl"
                        type="url"
                        class="w-full"
                      />
                    </UFormField>
                  </div>

                  <div class="flex flex-wrap items-center justify-between gap-3 border-t border-default pt-5">
                    <p class="text-sm text-muted">
                      Changes are reflected across all connected projects.
                    </p>
                    <UButton
                      type="submit"
                      label="Save profile"
                      icon="i-lucide-save"
                      :loading="pending"
                    />
                  </div>
                </form>
              </UPageCard>

              <template v-else>
                <UPageCard
                  title="Sign-in password"
                  :description="passwordUnavailable
                    ? 'Password management is unavailable because this account session was authenticated with Google.'
                    : 'Update your password to secure your account.'"
                  variant="subtle"
                  :ui="{ container: 'gap-6 p-5 sm:p-6' }"
                >
                  <form
                    class="space-y-6"
                    @submit.prevent="changePassword"
                  >
                    <UAlert
                      v-if="passwordUnavailable"
                      color="neutral"
                      variant="subtle"
                      icon="i-simple-icons-google"
                      title="Signed in with Google"
                      description="This account does not currently expose password management. Continue using Google to sign in."
                    />
                    <div class="grid gap-5 md:grid-cols-3">
                      <UFormField
                        label="Current password"
                        required
                      >
                        <UInput
                          v-model="password.current"
                          type="password"
                          autocomplete="current-password"
                          class="w-full"
                          :disabled="passwordUnavailable"
                        />
                      </UFormField>
                      <UFormField
                        label="New password"
                        required
                      >
                        <UInput
                          v-model="password.next"
                          type="password"
                          autocomplete="new-password"
                          class="w-full"
                          :disabled="passwordUnavailable"
                        />
                      </UFormField>
                      <UFormField
                        label="Confirm new password"
                        required
                      >
                        <UInput
                          v-model="password.confirmation"
                          type="password"
                          autocomplete="new-password"
                          class="w-full"
                          :disabled="passwordUnavailable"
                        />
                      </UFormField>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-default pt-5">
                      <p class="text-sm text-muted">
                        Other active sessions are revoked when this change succeeds.
                      </p>
                      <UButton
                        type="submit"
                        label="Change password"
                        icon="i-lucide-key-round"
                        :loading="pending"
                        :disabled="passwordUnavailable"
                      />
                    </div>
                  </form>
                </UPageCard>

                <UPageCard
                  title="Data controls"
                  description="Download your account data for portability and records."
                  variant="subtle"
                  :ui="{ container: 'gap-6 p-5 sm:p-6' }"
                >
                  <div class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-default p-4">
                    <div class="space-y-1">
                      <p class="font-medium text-highlighted">
                        Export account JSON
                      </p>
                      <p class="text-sm text-muted">
                        Includes profile data and related identity account records.
                      </p>
                    </div>

                    <UButton
                      label="Export JSON"
                      color="neutral"
                      variant="soft"
                      icon="i-lucide-download"
                      :loading="pending"
                      @click="exportAccount"
                    />
                  </div>
                </UPageCard>

                <UPageCard
                  title="Danger zone"
                  description="Permanently delete this identity account and revoke access everywhere."
                  variant="subtle"
                  :ui="{ container: 'gap-6 p-5 sm:p-6' }"
                >
                  <div class="rounded-xl border border-error/35 bg-error/5 p-4 sm:p-5">
                    <div class="flex items-start gap-3">
                      <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-error/10 text-error">
                        <UIcon
                          name="i-lucide-triangle-alert"
                          class="size-4.5"
                        />
                      </span>
                      <div>
                        <p class="font-medium text-error">
                          Delete account
                        </p>
                        <p class="mt-1 text-sm text-muted">
                          This action is irreversible. All account data will be removed.
                        </p>
                      </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:max-w-sm">
                      <UInput
                        v-model="deleteConfirmation"
                        placeholder="Type DELETE to confirm"
                      />
                      <UButton
                        label="Delete account"
                        color="error"
                        :disabled="deleteConfirmation !== 'DELETE'"
                        :loading="pending"
                        @click="deleteAccount"
                      />
                    </div>
                  </div>
                </UPageCard>
              </template>
            </section>
          </div>
        </template>

        <div
          v-else
          class="identity-account-loading-state"
          aria-busy="true"
          aria-label="Loading account settings"
        >
          <IdentityAuthBrandMark
            :logo-url="brand?.presentation.logoUrl"
            :accent-color="brand?.presentation.accentColor"
            size="form"
            class="animate-pulse"
          />
        </div>
      </div>
    </UContainer>
  </main>
</template>

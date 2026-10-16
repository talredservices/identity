<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'
import { useIdentityMutation } from '../../../app/composables/useIdentityMutation'

type HostedBrand = {
  name: string
  returnUrl: string
  presentation: {
    pageSet: string
    productName: string
    logoUrl: string | null
    accentColor: string | null
    backgroundPreset: 'identity' | 'slate' | 'indigo' | 'emerald' | 'sunset'
    designTokens: Record<string, string>
  }
  authentication?: { termsUrl?: string | null, privacyUrl?: string | null }
}

type HostedAccountContext = {
  user: {
    username?: string
    email: string
    avatar_url?: string | null
  } | null
}

const route = useRoute()
const mutate = useIdentityMutation()
const auth = useIdentityAuth()
const colorMode = useColorMode()
const { locale } = useI18n()
const applicationKey = computed(() =>
  typeof route.query.application === 'string' ? route.query.application : ''
)
const { data: brand } = await useAsyncData<HostedBrand | null>(
  'identity-account-brand',
  async () => {
    if (!applicationKey.value) return null
    const response = await $fetch<{ application: HostedBrand }>(
      '/api/hosted-auth/application',
      {
        query: { application: applicationKey.value }
      }
    )
    return response.application
  },
  { watch: [applicationKey] }
)
const { data: accountContext, pending: accountContextPending } = await useFetch<HostedAccountContext>(
  '/api/hosted-account/context',
  {
    key: computed(() => `identity-hosted-account-context:${applicationKey.value}`),
    query: { application: applicationKey },
    server: false,
    watch: [applicationKey]
  }
)
const brandStyle = computed(() => {
  const tokens = brand.value?.presentation.designTokens ?? {}
  const styles: Record<string, string> = {}

  for (const [key, value] of Object.entries(tokens)) {
    styles[`--identity-hosted-${key.replaceAll('_', '-')}`] = value
    if (key.startsWith('primary_')) styles[`--ui-color-primary-${key.slice(8)}`] = value
  }

  const accent = tokens.accent ?? brand.value?.presentation.accentColor ?? '#3157d5'
  styles['--identity-hosted-accent'] = accent
  styles['--ui-primary'] = tokens.primary_600 ?? accent
  styles['--ui-color-primary-500'] = tokens.primary_500 ?? accent
  styles['--ui-color-primary-700'] = tokens.primary_700 ?? accent
  return styles
})
provide('identity-account-brand', brand)
const loggingOut = ref(false)
const accountUser = computed(() => accountContext.value?.user)
const accountUserName = computed(
  () => accountUser.value?.username || accountUser.value?.email || 'Account'
)
const accountUserAvatar = computed(() => ({
  src: accountUser.value?.avatar_url
    || `https://api.dicebear.com/9.x/initials/svg?seed=${encodeURIComponent(accountUserName.value)}&backgroundColor=0D8ABC&color=fff`,
  alt: accountUserName.value
}))

const userMenuItems = computed<DropdownMenuItem[][]>(() => [
  [
    {
      type: 'label',
      label: accountUserName.value,
      description: accountUser.value?.email || brand.value?.name || undefined,
      avatar: accountUserAvatar.value
    }
  ],
  [
    {
      label: 'Return to application',
      icon: 'i-lucide-house',
      onSelect: () => navigateTo(brand.value?.returnUrl ?? '/', { external: true })
    }
  ],
  [
    {
      label: 'Language',
      icon: 'i-lucide-languages',
      children: [
        { label: 'English', type: 'checkbox', checked: locale.value === 'en' },
        { label: 'Français', type: 'checkbox', checked: locale.value === 'fr' }
      ]
    },
    {
      label: 'Appearance',
      icon: 'i-lucide-sun-moon',
      children: [
        {
          label: 'Light',
          icon: 'i-lucide-sun',
          type: 'checkbox',
          checked: colorMode.value === 'light',
          onSelect(event: Event) {
            event.preventDefault()
            colorMode.preference = 'light'
          }
        },
        {
          label: 'Dark',
          icon: 'i-lucide-moon',
          type: 'checkbox',
          checked: colorMode.value === 'dark',
          onSelect(event: Event) {
            event.preventDefault()
            colorMode.preference = 'dark'
          }
        }
      ]
    }
  ],
  [
    {
      label: 'Log out',
      icon: 'i-lucide-log-out',
      color: 'error',
      onSelect: logout
    }
  ]
])

async function logout() {
  if (loggingOut.value) return
  loggingOut.value = true
  try {
    if (!applicationKey.value) {
      await auth.logout()
      await navigateTo('/auth/login')
      return
    }

    await mutate('/api/hosted-account/logout', {
      method: 'POST',
      body: { application: applicationKey.value }
    })
    const destination = new URL(`/api/identity/${encodeURIComponent(applicationKey.value)}/account/logout`, new URL(brand.value?.returnUrl ?? '/', window.location.origin).origin)
    await navigateTo(destination.toString(), { external: true })
  } catch (error) {
    loggingOut.value = false
    throw error
  }
}
</script>

<template>
  <div
    class="identity-account-layout flex min-h-screen flex-col bg-default text-default"
    :style="brandStyle"
  >
    <div
      v-if="loggingOut"
      class="fixed inset-0 z-50 grid place-items-center bg-default/85 px-4 backdrop-blur-sm"
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <div class="flex w-full max-w-sm flex-col items-center gap-3 rounded-2xl border border-default bg-default p-8 text-center shadow-xl">
        <UIcon
          name="i-lucide-loader-circle"
          class="size-7 animate-spin text-primary"
        />
        <p class="m-0 font-medium text-highlighted">
          Signing you out…
        </p>
        <p class="m-0 text-sm text-muted">
          Your account session is being closed securely.
        </p>
      </div>
    </div>

    <UHeader :toggle="false">
      <template #left>
        <a
          :href="brand?.returnUrl ?? '/'"
          class="group flex min-w-0 items-center gap-2.5"
          :aria-label="`Return to ${brand?.presentation.productName ?? brand?.name ?? 'application'}`"
        >
          <IdentityAuthBrandMark
            :logo-url="brand?.presentation.logoUrl"
            :accent-color="brand?.presentation.accentColor"
            size="compact"
          />
          <span
            class="truncate text-sm font-semibold tracking-tight text-highlighted transition-colors group-hover:text-primary"
          >
            {{ brand?.presentation.productName ?? brand?.name ?? 'Application' }}
          </span>
        </a>
      </template>

      <template #right>
        <span
          v-if="accountContextPending || !accountContext"
          class="flex items-center gap-2"
          aria-label="Loading account menu"
          role="status"
        >
          <USkeleton class="size-6 rounded-full !bg-inverted/10" />
          <USkeleton class="h-3.5 w-12 !bg-inverted/10" />
          <USkeleton class="size-3 !bg-inverted/10" />
        </span>
        <UDropdownMenu
          v-else
          :items="userMenuItems"
          :content="{ align: 'end', collisionPadding: 12 }"
          :ui="{ content: 'w-56' }"
        >
          <UButton
            :label="accountUserName"
            :avatar="accountUserAvatar"
            trailing-icon="i-lucide-chevrons-up-down"
            color="neutral"
            variant="ghost"
            :loading="loggingOut"
            aria-label="Open account menu"
            class="data-[state=open]:bg-elevated"
            :ui="{ trailingIcon: 'text-dimmed' }"
          />
        </UDropdownMenu>
      </template>
    </UHeader>

    <slot />

    <footer
      v-if="
        brand?.authentication?.termsUrl || brand?.authentication?.privacyUrl
      "
      class="border-t border-default"
    >
      <UContainer
        class="flex flex-wrap items-center gap-x-3 gap-y-1 py-4 text-sm text-muted"
      >
        <a
          v-if="brand.authentication.termsUrl"
          :href="brand.authentication.termsUrl"
          target="_blank"
          rel="noopener"
          class="hover:text-default"
        >Terms of Service</a>
        <span
          v-if="
            brand.authentication.termsUrl && brand.authentication.privacyUrl
          "
          aria-hidden="true"
        >·</span>
        <a
          v-if="brand.authentication.privacyUrl"
          :href="brand.authentication.privacyUrl"
          target="_blank"
          rel="noopener"
          class="hover:text-default"
        >Privacy Policy</a>
      </UContainer>
    </footer>

    <IdentityAttribution />
  </div>
</template>

<style>
.identity-account-layout {
  --ui-bg: var(--identity-hosted-light-background, #faf9f7);
  --ui-bg-muted: var(--identity-hosted-light-background-muted, #eef3ef);
  --ui-bg-elevated: var(--identity-hosted-light-card, #fff);
  --ui-bg-accented: var(--identity-hosted-light-background-muted, #eef3ef);
  --ui-text: var(--identity-hosted-light-text, #172033);
  --ui-text-muted: var(--identity-hosted-light-muted, #59657b);
  --ui-text-highlighted: var(--identity-hosted-light-text, #172033);
  --ui-border: var(--identity-hosted-light-border, #e1e5ee);
  --ui-border-muted: var(--identity-hosted-light-border, #e1e5ee);
  color: var(--identity-hosted-light-text, #172033);
  font-family: var(--identity-hosted-font-family, Inter), ui-sans-serif, system-ui, sans-serif;
}

.dark .identity-account-layout {
  --ui-bg: var(--identity-hosted-dark-background, #0f172a);
  --ui-bg-muted: var(--identity-hosted-dark-background-muted, #0f172a);
  --ui-bg-elevated: var(--identity-hosted-dark-card, #111827);
  --ui-bg-accented: var(--identity-hosted-dark-background-muted, #0f172a);
  --ui-text: var(--identity-hosted-dark-text, #f8fafc);
  --ui-text-muted: var(--identity-hosted-dark-muted, #cbd5e1);
  --ui-text-highlighted: var(--identity-hosted-dark-text, #f8fafc);
  --ui-border: var(--identity-hosted-dark-border, #334155);
  --ui-border-muted: var(--identity-hosted-dark-border, #334155);
  color: var(--identity-hosted-dark-text, #f8fafc);
}

.identity-account-loading-state {
  display: grid;
  min-height: 20rem;
  width: 100%;
  place-items: center;
}

.identity-account-layout:has(.identity-account-loading-state) .identity-attribution {
  display: none;
}
</style>

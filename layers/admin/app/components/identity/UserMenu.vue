<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

defineProps<{
  collapsed?: boolean
}>()

const colorMode = useColorMode()
const access = useIdentityAccess()
const userSession = useUserSession()
const route = useRoute()
const localePath = useLocalePath()
const switchLocalePath = useSwitchLocalePath()
const { locale, t } = useI18n()

const supportedLocales = [
  { code: 'en', labelKey: 'userMenu.locales.en' },
  { code: 'fr', labelKey: 'userMenu.locales.fr' }
] as const

type SupportedLocale = (typeof supportedLocales)[number]['code']

const user = computed(() => {
  const sessionUser = userSession.user.value as {
    name?: string
    username?: string
    email?: string
    avatar_url?: string | null
  } | null

  const name = sessionUser?.name || sessionUser?.username || t('identityConsole.administrator')
  const email = sessionUser?.email || ''
  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(part => part[0])
    .join('')
    .toUpperCase()

  return {
    name,
    email,
    avatar: {
      ...(sessionUser?.avatar_url
        ? { src: sessionUser.avatar_url }
        : { text: initials, color: 'primary' as const }),
      alt: name
    }
  }
})

const showAccountItem = computed(() => !route.path.startsWith(localePath('/admin/account')))
const accountPath = localePath('/admin/account')
const projectsPath = localePath('/admin/projects')

const items = computed<DropdownMenuItem[][]>(() => [
  [
    {
      type: 'label',
      label: user.value.name,
      description: user.value.email || undefined,
      avatar: user.value.avatar,
      ui: {
        itemDescription: 'text-muted text-xs'
      }
    }
  ],
  [
    ...(showAccountItem.value
      ? [{
          label: t('identityConsole.nav.account'),
          icon: 'i-lucide-user-cog',
          to: accountPath
        }]
      : []),
    {
      label: t('identityConsole.nav.projects'),
      icon: 'i-lucide-layout-dashboard',
      to: projectsPath
    }
  ],
  [
    {
      label: t('userMenu.language'),
      icon: 'i-lucide-languages',
      children: supportedLocales.map(entry => ({
        label: t(entry.labelKey),
        type: 'checkbox',
        checked: locale.value === entry.code,
        onUpdateChecked(checked: boolean) {
          if (!checked) {
            return
          }

          const path = switchLocalePath(entry.code as SupportedLocale)
          if (path && path !== route.fullPath) {
            return navigateTo(path)
          }
        }
      }))
    },
    {
      label: t('userMenu.appearance'),
      icon: 'i-lucide-sun-moon',
      children: [
        {
          label: t('userMenu.light'),
          icon: 'i-lucide-sun',
          type: 'checkbox',
          checked: colorMode.value === 'light',
          onSelect(e: Event) {
            e.preventDefault()

            colorMode.preference = 'light'
          }
        },
        {
          label: t('userMenu.dark'),
          icon: 'i-lucide-moon',
          type: 'checkbox',
          checked: colorMode.value === 'dark',
          onUpdateChecked(checked: boolean) {
            if (checked) {
              colorMode.preference = 'dark'
            }
          },
          onSelect(e: Event) {
            e.preventDefault()
          }
        }
      ]
    }
  ],
  [
    {
      label: t('identityConsole.signOut'),
      icon: 'i-lucide-log-out',
      async onSelect(e?: Event) {
        e?.preventDefault()
        await access.logout()
        await userSession.fetch()
        await navigateTo(localePath('/admin/sign-in'))
      }
    }
  ]
])

onMounted(async () => {
  if (!userSession.ready.value) {
    await userSession.fetch()
  }
})
</script>

<template>
  <UDropdownMenu
    :items="items"
    :content="{ align: 'center', collisionPadding: 12 }"
    :ui="{
      content: collapsed ? 'w-48' : 'w-(--reka-dropdown-menu-trigger-width)'
    }"
  >
    <UButton
      v-bind="{
        ...user,
        label: collapsed ? undefined : t('userMenu.settings'),
        trailingIcon: collapsed ? undefined : 'i-lucide-chevrons-up-down'
      }"
      color="neutral"
      variant="ghost"
      block
      :square="collapsed"
      class="data-[state=open]:bg-elevated"
      :ui="{
        trailingIcon: 'text-dimmed'
      }"
    />

    <template #chip-leading="{ item }">
      <div class="inline-flex items-center justify-center shrink-0 size-5">
        <span
          class="rounded-full ring ring-bg bg-(--chip-light) dark:bg-(--chip-dark) size-2"
          :style="{
            '--chip-light': `var(--color-${(item as any).chip}-500)`,
            '--chip-dark': `var(--color-${(item as any).chip}-400)`
          }"
        />
      </div>
    </template>
  </UDropdownMenu>
</template>

<script setup lang="ts">
const open = ref(false)
const sidebarCollapsed = useCookie<boolean>('identity-console-sidebar-collapsed', {
  default: () => false,
  sameSite: 'lax'
})

const { t } = useI18n()

useHead({
  bodyAttrs: {
    class: 'identity-console-theme'
  }
})

const identityAccess = useIdentityAccess()
const userSession = useUserSession()

const { data: identitySession } = await identityAccess.session()

if (!userSession.ready.value) {
  await userSession.fetch()
}

const { primaryLinks, secondaryLinks, searchGroups }
  = useIdentityAdminNavigation(identitySession, () => {
    open.value = false
  })
</script>

<template>
  <IdentityShellFrame
    v-model:open="open"
    v-model:collapsed="sidebarCollapsed"
    sidebar-id="identity-console-admin"
    :primary-links="primaryLinks"
    :secondary-links="secondaryLinks"
    :search-groups="searchGroups"
    :primary-navigation-label="
      t('identityConsole.accessibility.primaryNavigation')
    "
    :secondary-navigation-label="
      t('identityConsole.accessibility.secondaryNavigation')
    "
    :search-label="t('identityConsole.accessibility.search')"
  >
    <template #sidebar-header="{ collapsed }">
      <IdentityConsoleBrand
        to="/admin"
        :collapsed="collapsed"
      />
    </template>

    <template #sidebar-start="{ collapsed }">
      <div class="pb-2">
        <UDashboardSearchButton
          :aria-label="t('identityConsole.accessibility.search')"
          :collapsed="collapsed"
          class="w-full bg-transparent ring-default"
        />
      </div>
    </template>

    <template #sidebar-footer="{ collapsed }">
      <IdentityUserMenu :collapsed="collapsed" />
    </template>

    <slot />
  </IdentityShellFrame>
</template>

import type {
  CommandPaletteGroup,
  CommandPaletteItem,
  NavigationMenuItem
} from '@nuxt/ui'
import type { Ref } from 'vue'
import type { IdentityBrowserSession } from '../../types/identity-access'

export function useIdentityAdminNavigation(
  identitySession: Ref<IdentityBrowserSession | null | undefined>,
  closeSidebar?: () => void
) {
  const route = useRoute()
  const localePath = useLocalePath()
  const { t } = useI18n()
  const onSelect = () => closeSidebar?.()

  const primaryLinks = computed<NavigationMenuItem[][]>(() => {
    const overviewPath = localePath('/admin')
    const projectsPath = localePath('/admin/projects')
    const clientsPath = localePath('/admin/clients')
    const applicationsPath = localePath('/admin/applications')
    const installationUsersPath = localePath('/admin/identity-users')
    const accessCatalogPath = localePath('/admin/access-catalog')

    const workspaceItems: NavigationMenuItem[] = [
      {
        label: t('identityConsole.nav.overview'),
        icon: 'i-lucide-house',
        to: overviewPath,
        active: route.path === overviewPath,
        onSelect
      },
      {
        label: t('identityConsole.nav.projects'),
        icon: 'i-lucide-folder-key',
        to: projectsPath,
        active: route.path === projectsPath || route.path.startsWith(`${projectsPath}/`),
        onSelect
      },
      {
        label: t('identityConsole.nav.clients'),
        icon: 'i-lucide-key-round',
        to: clientsPath,
        active: route.path === clientsPath || route.path.startsWith(`${clientsPath}/`),
        onSelect
      },
      {
        label: t('identityConsole.nav.applications'),
        icon: 'i-lucide-panels-top-left',
        to: applicationsPath,
        active: route.path === applicationsPath || route.path.startsWith(`${applicationsPath}/`),
        onSelect
      }
    ]

    const directoryItems: NavigationMenuItem[] = []
    const accessItems: NavigationMenuItem[] = []

    if (identitySession.value?.isSystemAdmin) {
      directoryItems.push({
        label: t('identityConsole.nav.users'),
        icon: 'i-lucide-users',
        to: installationUsersPath,
        active: route.path === installationUsersPath || route.path.startsWith(`${installationUsersPath}/`),
        onSelect
      })
      accessItems.push({
        label: t('identityConsole.nav.accessCatalog'),
        icon: 'i-lucide-library',
        to: accessCatalogPath,
        active: route.path === accessCatalogPath || route.path.startsWith(`${accessCatalogPath}/`),
        onSelect
      })
    }

    return [
      workspaceItems,
      directoryItems,
      accessItems
    ].filter(items => items.length > 0)
  })

  const secondaryLinks = computed<NavigationMenuItem[][]>(() => {
    return [[{
      label: t('identityConsole.nav.account'),
      icon: 'i-lucide-user-cog',
      to: localePath('/admin/account'),
      active: route.path === localePath('/admin/account'),
      onSelect
    }]]
  })

  const searchGroups = computed<CommandPaletteGroup<CommandPaletteItem>[]>(() => [{
    id: 'identity-navigation',
    label: t('identityConsole.navigate'),
    items: [
      ...primaryLinks.value.flat().map(item => ({
        label: item.label,
        icon: item.icon,
        to: item.to
      })),
      {
        label: t('identityConsole.nav.account'),
        icon: 'i-lucide-user-cog',
        to: localePath('/admin/account')
      }
    ]
  }])

  return { primaryLinks, secondaryLinks, searchGroups }
}

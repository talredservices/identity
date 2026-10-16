export function useIdentitySelectedProject() {
  const cookie = useCookie<string | null>('identity-console-selected-project', {
    default: () => null,
    sameSite: 'lax',
    path: '/'
  })
  const selectedProjectId = useState<string | null>('identity-console-selected-project', () => cookie.value)

  watch(selectedProjectId, (value) => {
    if (cookie.value !== value) cookie.value = value
  }, { immediate: true, flush: 'sync' })

  watch(cookie, (value) => {
    if (selectedProjectId.value !== value) selectedProjectId.value = value ?? null
  }, { flush: 'sync' })

  return selectedProjectId
}

function getResponseStatus(error: unknown): number | undefined {
  if (!error || typeof error !== 'object') {
    return undefined
  }

  const candidate = error as {
    status?: unknown
    statusCode?: unknown
    response?: { status?: unknown }
    data?: { statusCode?: unknown }
  }

  return [
    candidate.status,
    candidate.statusCode,
    candidate.response?.status,
    candidate.data?.statusCode
  ].find((value): value is number => typeof value === 'number')
}

export function useUnauthorizedSessionHandler() {
  const session = useUserSession()
  const localePath = useLocalePath()
  const config = useRuntimeConfig()
  const redirecting = useState('auth:redirecting', () => false)

  const isUnauthorizedError = (error: unknown) => getResponseStatus(error) === 401

  const clearSessionAndRedirect = async () => {
    if (import.meta.server || redirecting.value) {
      return
    }

    redirecting.value = true

    try {
      await session.clear()
    } finally {
      const publicHost = config.public.identityAdmin.publicHost.trim().toLowerCase()
      const loginPath = window.location.hostname.toLowerCase() === publicHost
        ? localePath('/auth/login')
        : localePath('/admin/sign-in')
      await navigateTo(loginPath, { replace: true })
      redirecting.value = false
    }
  }

  return {
    isUnauthorizedError,
    clearSessionAndRedirect
  }
}

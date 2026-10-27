export type HostedAuthRestartAction = 'login' | 'signup' | 'forgot-password' | 'reset-password'

export function hostedAuthRestartAction(path: string): HostedAuthRestartAction {
  const screen = path.split('/').filter(Boolean).at(-1)

  if (screen === 'register') return 'signup'
  if (screen === 'forgot-password') return 'forgot-password'
  if (screen === 'reset-password') return 'reset-password'

  return 'login'
}

function safeLocalRedirect(value: unknown): value is string {
  return typeof value === 'string'
    && value.startsWith('/')
    && !value.startsWith('//')
}

export function hostedAuthRestartUrl(input: {
  returnUrl: string
  identityOrigin: string
  action: HostedAuthRestartAction
  redirect?: unknown
}): string | null {
  let target: URL
  let identityOrigin: string

  try {
    target = new URL(input.returnUrl)
    identityOrigin = new URL(input.identityOrigin).origin
  } catch {
    return null
  }

  if (
    !['http:', 'https:'].includes(target.protocol)
    || target.username
    || target.password
    || target.origin === identityOrigin
  ) {
    return null
  }

  const applicationPath = target.pathname || '/'
  const localePrefix = applicationPath.match(/^\/([a-z]{2}(?:-[a-z]{2})?)(?=\/|$)/i)?.[0] ?? ''
  const redirect = safeLocalRedirect(input.redirect)
    ? input.redirect
    : applicationPath !== '/'
      ? applicationPath
      : undefined

  target.pathname = `${localePrefix}/auth/${input.action}`
  target.search = ''
  target.hash = ''
  if (redirect) target.searchParams.set('redirect', redirect)

  return target.toString()
}

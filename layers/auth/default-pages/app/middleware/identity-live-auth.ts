import type { IdentityAuthenticationExperience } from '../../../shared/types/identity-auth'

type HostedApplicationMetadata = {
  application: {
    returnUrl: string
  }
}

function isUnauthorized(error: unknown): boolean {
  const value = error as { status?: number | string, statusCode?: number | string, cause?: { status?: number | string, statusCode?: number | string } }
  return [value.status, value.statusCode, value.cause?.status, value.cause?.statusCode]
    .some(status => status === 401 || status === '401')
}

function hostedAuthRestartAction(path: string): string {
  const screen = path.split('/').filter(Boolean).at(-1)
  return screen === 'register' ? 'signup' : screen === 'forgot-password' ? 'forgot-password' : 'login'
}

function identityAuthPageSetFromRoute(path: string, routePageSet: unknown): string {
  const configuredPageSet = identityAuthPageSet(routePageSet)
  if (configuredPageSet !== 'default') return configuredPageSet

  const match = path.match(/^\/auth\/([^/]+)\/(?:login|register|forgot-password|reset-password|verify-email)$/)
  return match ? identityAuthPageSet(match[1]) : configuredPageSet
}

async function restartHostedAuthorization(
  requestFetch: ReturnType<typeof useRequestFetch>,
  to: { path: string, query: Record<string, unknown> },
  application: string
) {
  const metadata = await requestFetch<HostedApplicationMetadata>('/api/hosted-auth/application', {
    query: { application }
  })
  const target = new URL(metadata.application.returnUrl)
  const identityOrigin = useRequestURL().origin
  if (target.origin === identityOrigin) return null

  target.pathname = `${target.pathname.replace(/\/$/, '')}/auth/${hostedAuthRestartAction(to.path)}`
  target.search = ''
  const redirect = typeof to.query.redirect === 'string' ? to.query.redirect : ''
  if (redirect.startsWith('/') && !redirect.startsWith('//')) target.searchParams.set('redirect', redirect)
  return `${target.pathname}${target.search}`
}

export default defineNuxtRouteMiddleware(async (to) => {
  const requestFetch = useRequestFetch()
  const pageSet = identityAuthPageSetFromRoute(to.path, to.params.pageSet)
  const application = typeof to.query.application === 'string' ? to.query.application : ''
  const state = typeof to.query.state === 'string' ? to.query.state : ''
  const intent = typeof to.query.intent === 'string' ? to.query.intent : ''

  if (application && state && intent) {
    const screen = to.path.split('/').filter(Boolean).at(-1)
    if (
      screen === 'login'
      || screen === 'register'
      || screen === 'forgot-password'
      || screen === 'reset-password'
    ) {
      return navigateTo({
        path: '/api/hosted-auth/entry',
        query: {
          application,
          state,
          intent,
          screen,
          email: typeof to.query.email === 'string' ? to.query.email : undefined,
          token: typeof to.query.token === 'string' ? to.query.token : undefined
        }
      }, { external: true })
    }
  }

  let experience: IdentityAuthenticationExperience
  try {
    experience = await requestFetch<IdentityAuthenticationExperience>(
      application ? '/api/hosted-auth/context' : '/api/auth/context',
      {
        query: application
          ? {
              application
            }
          : undefined
      }
    )
  } catch (error) {
    if (application && isUnauthorized(error)) {
      const restart = await restartHostedAuthorization(requestFetch, to, application)
      if (restart) return navigateTo(restart, { external: true, replace: true })
    }
    throw error
  }

  if (experience.primary.project.mode !== 'live') {
    return navigateTo(identityAuthPagePath('login', pageSet, application ? to.query : {}))
  }

  if (
    to.path.endsWith('/register')
    && experience.primary.project.registration_mode !== 'public'
  ) {
    return navigateTo(identityAuthPagePath('login', pageSet, application ? to.query : {}))
  }
})

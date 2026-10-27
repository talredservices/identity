import { inject, unref, type Ref } from 'vue'
import type { LocationQueryRaw, RouteLocationAsPathGeneric } from 'vue-router'
import { FetchError } from 'ofetch'

export function identitySafeRedirect(
  value: unknown,
  fallback: string
): string {
  if (
    typeof value === 'string'
    && value.startsWith('/')
    && !value.startsWith('//')
  ) {
    return value
  }

  return fallback
}

export function identityAuthErrorMessage(
  error: unknown,
  fallback: string
): string {
  const candidate = error as {
    data?: {
      message?: string
      statusMessage?: string
    }
    message?: string
  }

  return candidate.data?.message
    ?? candidate.data?.statusMessage
    ?? candidate.message
    ?? fallback
}

/** Prevent credential failures from exposing transport jargon or account state. */
export function identityLoginErrorMessage(error: unknown): string {
  const fallback = 'We could not sign you in with those credentials.'

  return error instanceof FetchError
    && (error.status === 401 || error.response?.status === 401)
    ? fallback
    : identityAuthErrorMessage(error, fallback)
}

export function identityAuthPageSet(value: unknown): string {
  return typeof value === 'string' && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value)
    ? value
    : 'default'
}

export function identityAuthPageSetFromRoute(path: string, routePageSet: unknown): string {
  const configuredPageSet = identityAuthPageSet(routePageSet)
  if (configuredPageSet !== 'default') return configuredPageSet

  const match = path.match(/^\/auth\/([^/]+)\/(?:login|register|forgot-password|reset-password|verify-email)$/)
  return match ? identityAuthPageSet(match[1]) : configuredPageSet
}

export function identityAuthPagePath(
  screen: string,
  pageSet: unknown,
  query: LocationQueryRaw = {}
): RouteLocationAsPathGeneric {
  const inheritedPageSet = inject<string | Ref<string | null> | null>(
    'identity-auth-page-set',
    null
  )
  const resolvedPageSet = identityAuthPageSet(
    pageSet ?? unref(inheritedPageSet)
  )
  return {
    path: resolvedPageSet === 'default' ? `/auth/${screen}` : `/auth/${resolvedPageSet}/${screen}`,
    query
  }
}

import type { H3Event } from 'h3'
import { createError } from 'h3'
import { $fetch, type FetchOptions } from 'ofetch'
import { hash } from 'ohash'
import type {
  IdentityBrowserSession,
  IdentityAuthenticationContext,
  IdentityAuthenticationExperience,
  IdentityConnectionName,
  IdentityLoginData,
  IdentityLoginInput,
  IdentityRegisterInput,
  IdentityResetPasswordInput,
  IdentitySandboxSessionData
} from '../../shared/types/identity-auth'
import { toIdentityBrowserSession } from '../resources/identity-session.resource'

type IdentityEnvelope<T> = { data: T }
type IdentityRefreshRegistry = typeof globalThis & {
  __zoltaIdentityRefreshRequests?: Map<string, Promise<IdentityLoginData>>
}
type IdentityConfiguration = {
  baseURL: string
  project: string
  clientId: string
  clientSecret: string
}
type IdentityRequestTarget = {
  baseURL: string
  path: string
}

const refreshRegistry = globalThis as IdentityRefreshRegistry
const refreshRequests = refreshRegistry.__zoltaIdentityRefreshRequests
  ??= new Map<string, Promise<IdentityLoginData>>()
const refreshDeduplicationWindowMs = 5_000
const identityPrefix = '/api/v1/identity'

export function identityConfiguration(event: H3Event, connection: IdentityConnectionName = 'primary'): IdentityConfiguration {
  const runtimeConfig = useRuntimeConfig(event)
  const rootIdentity = runtimeConfig.identity
  const identity = connection === 'sandbox' ? rootIdentity.sandbox : rootIdentity
  const clientId = identity.clientId ?? ''
  const clientSecret = identity.clientSecret ?? ''

  if (!clientId || !clientSecret) {
    throw createError({
      statusCode: 503,
      statusMessage: 'The identity BFF client is not configured.'
    })
  }

  return {
    baseURL: (identity.apiUrl || 'http://localhost:8000').replace(/\/+$/, ''),
    project: identity.project ?? '',
    clientId,
    clientSecret
  }
}

function identityRequestTarget(config: IdentityConfiguration, path: string): IdentityRequestTarget {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`
  const baseIncludesPrefix = config.baseURL.endsWith(identityPrefix)

  if (normalizedPath.startsWith('/api/') && !normalizedPath.startsWith(identityPrefix)) {
    return {
      baseURL: baseIncludesPrefix
        ? config.baseURL.slice(0, -identityPrefix.length)
        : config.baseURL,
      path: normalizedPath
    }
  }

  if (baseIncludesPrefix && normalizedPath.startsWith(identityPrefix)) {
    return {
      baseURL: config.baseURL,
      path: normalizedPath.slice(identityPrefix.length) || '/'
    }
  }

  if (!baseIncludesPrefix && !normalizedPath.startsWith(identityPrefix)) {
    return {
      baseURL: config.baseURL,
      path: `${identityPrefix}${normalizedPath}`
    }
  }

  return { baseURL: config.baseURL, path: normalizedPath }
}

async function identityClientRequest<T>(
  event: H3Event,
  path: string,
  body: Record<string, unknown>,
  connection: IdentityConnectionName = 'primary'
): Promise<T> {
  const config = identityConfiguration(event, connection)
  const target = identityRequestTarget(config, path)
  const response = await $fetch<IdentityEnvelope<T>>(target.path, {
    baseURL: target.baseURL,
    method: 'POST',
    body
  })

  return response.data
}

export async function setIdentitySession(
  event: H3Event,
  data: IdentityLoginData,
  connection: IdentityConnectionName = 'primary'
): Promise<void> {
  const existing = await getUserSession(event)
  const existingUser = existing.user?.id === data.identity.user.id
    ? existing.user
    : undefined

  const sessionData = {
    user: {
      ...existingUser,
      id: data.identity.user.id,
      email: data.identity.user.email,
      name: data.identity.user.username,
      username: data.identity.user.username,
      emailVerified: data.identity.user.email_verified,
      isTemporary: data.identity.user.is_temporary,
      expiresAt: data.identity.user.temporary_expires_at
    },
    identity: toIdentityBrowserSession(data),
    secure: {
      ...existing.secure,
      identityAccessToken: data.access_token,
      identityAccessTokenExpiresAt: data.access_token_expires_at,
      identityRefreshToken: data.refresh_token,
      identityRefreshTokenExpiresAt: data.refresh_token_expires_at,
      identityConnection: connection
    },
    lastLoggedIn: existing.lastLoggedIn ?? new Date()
  } as unknown as Parameters<typeof setUserSession>[1]

  await setUserSession(event, sessionData)
}

export async function identityCreateSandboxSession(
  event: H3Event,
  connection: IdentityConnectionName = 'sandbox'
): Promise<IdentitySandboxSessionData> {
  const publicConfig = useRuntimeConfig(event).public as {
    identityAuth?: { sandboxEnabled?: boolean }
  }
  if (publicConfig.identityAuth?.sandboxEnabled !== true) {
    throw createError({ statusCode: 403, statusMessage: 'Demo sandbox accounts are disabled.' })
  }
  const config = identityConfiguration(event, connection)
  return await identityClientRequest<IdentitySandboxSessionData>(event, '/auth/sandbox-session', {
    client_id: config.clientId,
    client_secret: config.clientSecret
  }, connection)
}

export async function identitySandboxSession(
  event: H3Event,
  connection: IdentityConnectionName = 'sandbox'
): Promise<IdentityBrowserSession> {
  const data = await identityCreateSandboxSession(event, connection)
  await setIdentitySession(event, data, connection)
  return toIdentityBrowserSession(data)
}

export async function identityAuthenticationContext(
  event: H3Event,
  connection: IdentityConnectionName = 'primary'
): Promise<IdentityAuthenticationContext> {
  const config = identityConfiguration(event, connection)
  const context = await identityClientRequest<Omit<IdentityAuthenticationContext, 'connection'>>(
    event,
    '/auth/context',
    {
      project: config.project,
      client_id: config.clientId,
      client_secret: config.clientSecret
    },
    connection
  )

  return { ...context, connection }
}

export async function identityAuthenticationExperience(
  event: H3Event
): Promise<IdentityAuthenticationExperience> {
  const runtimeConfig = useRuntimeConfig(event)
  const primary = await identityAuthenticationContext(event)
  const sandboxConfig = runtimeConfig.identity.sandbox
  const publicConfig = runtimeConfig.public as {
    identityAuth?: { sandboxEnabled?: boolean }
    identitySandboxEnabled?: boolean
  }
  const shouldResolveSandbox = Boolean(
    publicConfig.identityAuth?.sandboxEnabled ?? publicConfig.identitySandboxEnabled
  ) && Boolean(sandboxConfig?.clientId && sandboxConfig?.clientSecret)

  if (!shouldResolveSandbox) {
    return { primary, sandbox: null }
  }

  const sandbox = await identityAuthenticationContext(event, 'sandbox')
  return { primary, sandbox }
}

export async function identityLogin(
  event: H3Event,
  credentials: IdentityLoginInput
): Promise<IdentityBrowserSession> {
  const data = await identityAuthenticate(event, credentials)
  await setIdentitySession(event, data)

  return toIdentityBrowserSession(data)
}

export async function identityAuthenticate(
  event: H3Event,
  credentials: IdentityLoginInput
): Promise<IdentityLoginData> {
  const config = identityConfiguration(event)
  return await identityClientRequest<IdentityLoginData>(event, '/auth/login', {
    project: config.project,
    client_id: config.clientId,
    client_secret: config.clientSecret,
    ...credentials
  })
}

export async function identityRegister(
  event: H3Event,
  input: IdentityRegisterInput
): Promise<IdentityBrowserSession> {
  const data = await identityRegisterAccount(event, input)
  await setIdentitySession(event, data)

  return toIdentityBrowserSession(data)
}

export async function identityRegisterAccount(
  event: H3Event,
  input: IdentityRegisterInput
): Promise<IdentityLoginData> {
  const config = identityConfiguration(event)
  return await identityClientRequest<IdentityLoginData>(event, '/auth/register', {
    project: config.project,
    client_id: config.clientId,
    client_secret: config.clientSecret,
    username: input.username,
    email: input.email,
    password: input.password,
    password_confirmation: input.passwordConfirmation
  })
}

export async function identityForgotPassword(
  event: H3Event,
  email: string
): Promise<Record<string, unknown>> {
  const config = identityConfiguration(event)
  return await identityClientRequest<Record<string, unknown>>(event, '/auth/password/forgot', {
    client_id: config.clientId,
    client_secret: config.clientSecret,
    email
  })
}

export async function identityResetPassword(
  event: H3Event,
  input: IdentityResetPasswordInput
): Promise<Record<string, unknown>> {
  const config = identityConfiguration(event)
  return await identityClientRequest<Record<string, unknown>>(event, '/auth/password/reset', {
    client_id: config.clientId,
    client_secret: config.clientSecret,
    email: input.email,
    token: input.token,
    password: input.password,
    password_confirmation: input.passwordConfirmation
  })
}

export async function requireIdentitySession(event: H3Event) {
  const session = await requireUserSession(event)
  const secure = session.secure as {
    identityAccessToken?: string | null
    identityAccessTokenExpiresAt?: string | null
    identityRefreshToken?: string | null
    identityRefreshTokenExpiresAt?: string | null
    identityConnection?: IdentityConnectionName
  }
  if (!session.identity || !secure.identityAccessToken || !secure.identityRefreshToken) {
    throw createError({
      statusCode: 401,
      statusMessage: 'An identity session is required.'
    })
  }

  return { session, secure }
}

export async function refreshIdentitySession(event: H3Event): Promise<string> {
  const { secure } = await requireIdentitySession(event)
  const connection = secure.identityConnection ?? 'primary'
  const config = identityConfiguration(event, connection)
  const refreshToken = secure.identityRefreshToken as string
  const requestKey = hash(refreshToken)
  let request = refreshRequests.get(requestKey)

  if (!request) {
    const createdRequest = identityClientRequest<IdentityLoginData>(event, '/auth/refresh', {
      client_id: config.clientId,
      client_secret: config.clientSecret,
      refresh_token: refreshToken
    }, connection)
    request = createdRequest
    refreshRequests.set(requestKey, createdRequest)
    void createdRequest.then(
      () => setTimeout(() => {
        if (refreshRequests.get(requestKey) === createdRequest) {
          refreshRequests.delete(requestKey)
        }
      }, refreshDeduplicationWindowMs),
      () => {
        if (refreshRequests.get(requestKey) === createdRequest) {
          refreshRequests.delete(requestKey)
        }
      }
    )
  }

  const data = await request
  await setIdentitySession(event, data, connection)

  return data.access_token
}

export async function getIdentityAccessToken(event: H3Event): Promise<string> {
  const { secure } = await requireIdentitySession(event)
  const expiresAt = Date.parse(secure.identityAccessTokenExpiresAt ?? '')
  if (Number.isFinite(expiresAt) && expiresAt > Date.now() + 30_000) {
    return secure.identityAccessToken as string
  }

  return await refreshIdentitySession(event)
}

export async function identityApi<T>(
  event: H3Event,
  path: string,
  options: FetchOptions<'json'> = {}
): Promise<T> {
  const { secure } = await requireIdentitySession(event)
  const config = identityConfiguration(event, secure.identityConnection ?? 'primary')
  const target = identityRequestTarget(config, path)
  const execute = (token: string) => $fetch<T>(target.path, {
    ...options,
    baseURL: target.baseURL,
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${token}`,
      ...options.headers
    }
  })

  try {
    return await execute(await getIdentityAccessToken(event))
  } catch (error: unknown) {
    const status = (error as { response?: { status?: number } }).response?.status
    if (status !== 401) throw error

    let refreshedToken: string
    try {
      refreshedToken = await refreshIdentitySession(event)
    } catch (refreshError) {
      await clearUserSession(event)
      throw refreshError
    }

    return await execute(refreshedToken)
  }
}

export async function identityLogout(event: H3Event): Promise<void> {
  const session = await getUserSession(event)
  const accessToken = session.secure?.identityAccessToken

  try {
    if (accessToken) {
      const connection = (session.secure?.identityConnection ?? 'primary') as IdentityConnectionName
      const config = identityConfiguration(event, connection)
      const target = identityRequestTarget(config, '/auth/logout')
      try {
        await $fetch(target.path, {
          baseURL: target.baseURL,
          method: 'POST',
          headers: { Authorization: `Bearer ${accessToken}` }
        })
      } catch (error: unknown) {
        const status = (error as { response?: { status?: number } }).response?.status

        // An expired or revoked Identity token is already signed out upstream.
        if (status !== 401) throw error
      }
    }
  } finally {
    await clearUserSession(event)
  }
}

export async function markIdentityEmailVerified(event: H3Event): Promise<void> {
  const { session } = await requireIdentitySession(event)
  const sessionData = {
    ...session,
    user: {
      ...session.user,
      emailVerified: true
    }
  } as unknown as Parameters<typeof setUserSession>[1]

  await setUserSession(event, sessionData)
}

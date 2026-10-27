import type { H3Event } from 'h3'
import { createError, getRequestURL, setResponseHeader, useSession } from 'h3'
import { $fetch, FetchError, type FetchOptions } from 'ofetch'
import type {
  IdentityAuthenticationContext,
  IdentityAuthenticationExperience,
  IdentityHostedApplicationMetadata,
  IdentityLoginData,
  IdentityLoginInput,
  IdentityRegisterInput,
  IdentityResetPasswordInput
} from '../../shared/types/identity-auth'
import { identityHostedPageSet } from '../../shared/hosted-auth-page-sets'
import { identityConfiguration, identityResetPassword } from './identity-api'

type HostedConnection = {
  apiUrl: string
  project: string
  clientId: string
}

type HostedApplication = {
  key: string
  name: string
  projectName: string
  callbackUrl: string
  presentation: IdentityHostedApplicationPublicPresentation
  applicationUrl?: string
  authentication: HostedApplicationAuthentication
  primary: HostedConnection
  sandbox?: HostedConnection
}

export type HostedApplicationPublicMetadata = IdentityHostedApplicationMetadata

type HostedApplicationAuthentication = {
  googleEnabled: boolean
  demoOnly: boolean
  termsRequired: boolean
  termsUrl: string | null
  privacyUrl: string | null
}

type IdentityHostedApplicationPublicPresentation = IdentityHostedApplicationMetadata['presentation']

type HostedFlow = {
  application: string
  state: string
  identity: IdentityLoginData
}

type HostedAuthorizationFlow = {
  application: string
  state: string
  features: {
    demoAccount: boolean
  }
}

type HostedSocialFlow = {
  application: string
  providerState: string
  purpose: 'authentication' | 'account'
  termsAccepted?: boolean
  state?: string
  tab?: 'profile' | 'security'
}

type HostedAccount = {
  application: string
  connection: 'primary' | 'sandbox'
  authenticationMethod?: 'password' | 'google'
  entryAuthorizedAt?: number
  identity?: IdentityLoginData
}

type IdentityEnvelope<T> = { data: T }

const identityPrefix = '/api/v1/identity'

function requiresEmailVerification(identity: IdentityLoginData): boolean {
  return identity.identity.project.email_verification_required
    && !identity.identity.user.email_verified
}

function identityErrorStatus(error: unknown): number | undefined {
  const candidate = error as {
    status?: number
    statusCode?: number
    response?: { status?: number }
    cause?: {
      status?: number
      statusCode?: number
      response?: { status?: number }
    }
  }

  return candidate.status
    ?? candidate.statusCode
    ?? candidate.response?.status
    ?? candidate.cause?.status
    ?? candidate.cause?.statusCode
    ?? candidate.cause?.response?.status
}

type HostedApplicationConfiguration = {
  key: string
  name: string
  project?: { name?: string, slug?: string }
  application_url: string
  callback_url: string
  auth_page_set?: string | null
  appearance?: {
    brand_name?: string | null
    brand_badge?: string | null
    welcome_text?: string | null
    accent_color?: string | null
    background_preset?: IdentityHostedApplicationMetadata['presentation']['backgroundPreset']
    logo_url?: string | null
    design_tokens?: Record<string, string>
  }
  authentication?: {
    google_enabled?: boolean
    demo_only?: boolean
    terms_required?: boolean
    terms_url?: string | null
    privacy_url?: string | null
  }
  primary: {
    project: string
    client_id: string
  }
  sandbox: { project: string, client_id: string } | null
}

function assertConnection(connection: HostedConnection | undefined): HostedConnection {
  if (!connection?.apiUrl || !connection.project || !connection.clientId) {
    throw createError({
      statusCode: 503,
      statusMessage: 'This hosted Identity application is not fully configured.'
    })
  }

  return connection
}

function assertHostedPrimaryAuthenticationAllowed(application: HostedApplication): void {
  if (application.authentication.demoOnly) {
    throw createError({
      statusCode: 403,
      statusMessage: 'Primary authentication is disabled for this application while demo-only sign-in is active.'
    })
  }
}

async function resolveHostedApplication(
  event: H3Event,
  path: string
): Promise<HostedApplication> {
  const config = useRuntimeConfig(event)
  const apiUrl = String(config.identity?.apiUrl ?? '')
  const token = String(config.identityHostedApplicationsToken ?? '')
  if (!apiUrl || !token) {
    throw createError({ statusCode: 503, statusMessage: 'Hosted Identity resolution is not configured.' })
  }

  const target = requestTarget({ apiUrl } as HostedConnection, path)
  const response = await $fetch<IdentityEnvelope<HostedApplicationConfiguration>>(target.path, {
    baseURL: target.baseURL,
    headers: { 'X-Internal-Token': token }
  })
  const application = response.data
  if (!application?.name || !application.callback_url || !application.primary) {
    throw createError({ statusCode: 502, statusMessage: 'Identity returned an invalid hosted application configuration.' })
  }

  const appearance = application.appearance

  return {
    key: application.key,
    name: application.name,
    projectName: application.project?.name ?? application.name,
    applicationUrl: application.application_url,
    callbackUrl: application.callback_url,
    presentation: {
      pageSet: identityHostedPageSet(application.auth_page_set, config.identityHostedAuthPageSets),
      productName: application.name,
      brandName: appearance?.brand_name ?? null,
      brandBadge: appearance?.brand_badge ?? null,
      welcomeText: appearance?.welcome_text ?? null,
      accentColor: appearance?.accent_color ?? null,
      backgroundPreset: appearance?.background_preset ?? 'identity',
      logoUrl: appearance?.logo_url ?? null,
      designTokens: appearance?.design_tokens ?? {}
    },
    authentication: {
      googleEnabled: application.authentication?.google_enabled ?? false,
      demoOnly: application.authentication?.demo_only ?? false,
      termsRequired: application.authentication?.terms_required ?? false,
      termsUrl: application.authentication?.terms_url ?? null,
      privacyUrl: application.authentication?.privacy_url ?? null
    },
    primary: assertConnection({
      apiUrl,
      project: application.primary.project,
      clientId: application.primary.client_id
    }),
    sandbox: application.sandbox
      ? assertConnection({ apiUrl, project: application.sandbox.project, clientId: application.sandbox.client_id })
      : undefined
  }
}

export async function identityHostedApplication(
  event: H3Event,
  application: string
): Promise<HostedApplication> {
  return await resolveHostedApplication(event, `/hosted-applications/${encodeURIComponent(application)}/configuration`)
}

export async function identityHostedApplicationByClient(
  event: H3Event,
  clientId: string
): Promise<HostedApplication> {
  return await resolveHostedApplication(event, `/hosted-clients/${encodeURIComponent(clientId)}/configuration`)
}

function hostedApplicationReturnUrl(event: H3Event, application: HostedApplication): string {
  const identityOrigin = getRequestURL(event).origin
  const callbackOrigin = new URL(application.callbackUrl).origin
  const configuredUrl = application.applicationUrl?.trim()

  if (configuredUrl) {
    try {
      const target = new URL(configuredUrl)
      // A hosted application must not return users to the Identity host. This
      // protects reset/login flows from stale bootstrap data such as the
      // Identity root URL, which is not an application entry point.
      if (target.origin !== identityOrigin || target.pathname !== '/') {
        return target.toString()
      }
    } catch {
      // Fall through to the validated callback origin.
    }
  }

  return callbackOrigin !== identityOrigin
    ? callbackOrigin
    : `${identityOrigin}/auth/login`
}

function publicApplicationMetadata(event: H3Event, application: HostedApplication): HostedApplicationPublicMetadata {
  return {
    key: application.key,
    name: application.name,
    projectName: application.projectName,
    returnUrl: hostedApplicationReturnUrl(event, application),
    presentation: application.presentation,
    authentication: application.authentication
  }
}

export function identityHostedAuthPageRoute(
  application: HostedApplication,
  screen: 'login' | 'register' | 'forgot-password' | 'reset-password' | 'verify-email'
): string {
  const pageSet = application.presentation.pageSet
  const prefix = pageSet !== 'default' ? `/auth/${encodeURIComponent(pageSet)}` : '/auth'
  return `${prefix}/${screen}`
}

export async function identityHostedApplicationMetadata(
  event: H3Event,
  applicationKey?: string,
  clientId?: string
): Promise<HostedApplicationPublicMetadata> {
  const application = applicationKey
    ? await identityHostedApplication(event, applicationKey)
    : await identityHostedApplicationByClient(event, clientId ?? '')

  return publicApplicationMetadata(event, application)
}

function requestTarget(connection: HostedConnection, path: string) {
  const baseURL = connection.apiUrl.replace(/\/+$/, '')
  const normalizedPath = path.startsWith('/') ? path : `/${path}`
  const baseIncludesPrefix = baseURL.endsWith(identityPrefix)

  if (normalizedPath.startsWith('/api/') && !normalizedPath.startsWith(identityPrefix)) {
    return {
      baseURL: baseIncludesPrefix ? baseURL.slice(0, -identityPrefix.length) : baseURL,
      path: normalizedPath
    }
  }

  if (baseIncludesPrefix && normalizedPath.startsWith(identityPrefix)) {
    return {
      baseURL,
      path: normalizedPath.slice(identityPrefix.length) || '/'
    }
  }

  return {
    baseURL,
    path: baseIncludesPrefix || normalizedPath.startsWith(identityPrefix)
      ? normalizedPath
      : `${identityPrefix}${normalizedPath}`
  }
}

async function connectionRequest<T>(
  connection: HostedConnection,
  path: string,
  options: FetchOptions<'json'> = {}
): Promise<T> {
  const target = requestTarget(connection, path)
  return await $fetch<T>(target.path, {
    baseURL: target.baseURL,
    ...options,
    headers: {
      Accept: 'application/json',
      ...options.headers
    }
  })
}

async function clientRequest<T>(
  connection: HostedConnection,
  path: string,
  body: Record<string, unknown>,
  accessToken?: string
): Promise<T> {
  const response = await connectionRequest<IdentityEnvelope<T>>(connection, path, {
    method: 'POST',
    body,
    headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : undefined
  })

  return response.data
}

function accountSession(event: H3Event, applicationKey: string) {
  const config = useRuntimeConfig(event)
  const password = String(config.session?.password ?? '')
  if (password.length < 32) {
    throw createError({ statusCode: 503, statusMessage: 'Identity hosted sessions are not configured.' })
  }

  if (!/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/.test(applicationKey)) {
    throw createError({ statusCode: 400, statusMessage: 'Invalid Identity application key.' })
  }

  return useSession<HostedAccount>(event, {
    password,
    name: `identity-hosted-account-${applicationKey}`,
    maxAge: 60 * 60,
    cookie: {
      path: '/',
      httpOnly: true,
      sameSite: 'lax',
      secure: process.env.NODE_ENV === 'production'
    }
  })
}

async function hostedRequest<T>(
  event: H3Event,
  application: HostedApplication,
  path: string,
  body: Record<string, unknown> = {},
  accessToken?: string
): Promise<T> {
  const config = useRuntimeConfig(event)
  const apiUrl = String(config.identity?.apiUrl ?? '')
  const token = String(config.identityHostedApplicationsToken ?? '')
  if (!apiUrl || !token) {
    throw createError({ statusCode: 503, statusMessage: 'Hosted Identity authentication is not configured.' })
  }
  const target = requestTarget({ apiUrl, project: '', clientId: '' }, `/hosted-applications/${encodeURIComponent(application.key)}/auth${path}`)
  const response = await $fetch<IdentityEnvelope<T>>(target.path, {
    baseURL: target.baseURL,
    method: 'POST',
    body,
    headers: {
      'X-Internal-Token': token,
      ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {})
    }
  })

  return response.data
}

function assertState(state: string): string {
  if (!/^[A-Za-z0-9_-]{32,180}$/.test(state)) {
    throw createError({ statusCode: 422, statusMessage: 'The authentication state is invalid.' })
  }

  return state
}

async function handoff(
  event: H3Event,
  application: HostedApplication,
  identity: IdentityLoginData,
  state: string
): Promise<{ redirectUrl: string }> {
  await requireHostedAuthorization(event, application, state)
  const connection = application.sandbox?.clientId === identity.identity.client.id
    ? 'sandbox'
    : 'primary'
  const result = await hostedRequest<{ code: string, redirect_uri: string }>(
    event,
    application,
    '/handoff',
    { connection },
    identity.access_token
  )
  const redirect = new URL(result.redirect_uri)
  redirect.searchParams.set('code', result.code)
  redirect.searchParams.set('state', assertState(state))
  redirect.searchParams.set('connection', connection)
  await (await authorizationFlowSession(event)).clear()

  return { redirectUrl: redirect.toString() }
}

function flowSession(event: H3Event) {
  const config = useRuntimeConfig(event)
  const password = String(config.session?.password ?? '')
  if (password.length < 32) {
    throw createError({ statusCode: 503, statusMessage: 'Identity hosted sessions are not configured.' })
  }

  return useSession<HostedFlow>(event, {
    password,
    name: 'identity-hosted-flow',
    maxAge: 60 * 15,
    cookie: {
      path: '/',
      httpOnly: true,
      sameSite: 'lax',
      secure: process.env.NODE_ENV === 'production'
    }
  })
}

function authorizationFlowSession(event: H3Event) {
  const config = useRuntimeConfig(event)
  const password = String(config.session?.password ?? '')
  if (password.length < 32) {
    throw createError({ statusCode: 503, statusMessage: 'Identity hosted sessions are not configured.' })
  }

  return useSession<HostedAuthorizationFlow>(event, {
    password,
    name: 'identity-hosted-authorization-flow',
    maxAge: 60 * 10,
    cookie: {
      path: '/',
      httpOnly: true,
      sameSite: 'lax',
      secure: process.env.NODE_ENV === 'production'
    }
  })
}

async function hostedAuthorization(
  event: H3Event,
  application: HostedApplication,
  intent?: string,
  expectedState?: string
): Promise<HostedAuthorizationFlow> {
  const session = await authorizationFlowSession(event)
  const existingState = session.data.state

  if (
    session.data.application === application.key
    && existingState
    && session.data.features
    && (!expectedState || existingState === assertState(expectedState))
  ) {
    return session.data
  }

  if (intent) {
    const authorization = await hostedRequest<{
      state: string
      features: { demo_account: boolean }
    }>(event, application, '/authorization/intent/consume', { intent })
    const state = assertState(authorization.state)
    if (expectedState && state !== assertState(expectedState)) {
      throw createError({ statusCode: 401, statusMessage: 'The hosted authentication request is invalid or expired.' })
    }
    await session.update({
      application: application.key,
      state,
      features: { demoAccount: authorization.features.demo_account === true }
    })
  }

  if (
    session.data.application !== application.key
    || !session.data.state
    || !session.data.features
  ) {
    throw createError({
      statusCode: 401,
      statusMessage: 'Open sign in from the application to continue.'
    })
  }

  return session.data
}

export async function establishIdentityHostedAuthorization(
  event: H3Event,
  applicationKey: string,
  intent: string,
  state: string
): Promise<HostedApplication> {
  const application = await identityHostedApplication(event, applicationKey)
  await hostedAuthorization(event, application, intent, state)
  return application
}

async function requireHostedAuthorization(
  event: H3Event,
  application: HostedApplication,
  state: string
): Promise<HostedAuthorizationFlow> {
  const authorization = await hostedAuthorization(event, application)
  if (authorization.state !== assertState(state)) {
    throw createError({ statusCode: 401, statusMessage: 'The hosted authentication request is invalid or expired.' })
  }

  return authorization
}

function socialFlowSession(event: H3Event) {
  const config = useRuntimeConfig(event)
  const password = String(config.session?.password ?? '')
  if (password.length < 32) throw createError({ statusCode: 503, statusMessage: 'Identity hosted sessions are not configured.' })

  return useSession<HostedSocialFlow>(event, {
    password,
    name: 'identity-hosted-social-flow',
    maxAge: 60 * 10,
    cookie: { path: '/', httpOnly: true, sameSite: 'lax', secure: process.env.NODE_ENV === 'production' }
  })
}

async function context(event: H3Event, application: HostedApplication, name: 'primary' | 'sandbox') {
  const value = await hostedRequest<Omit<IdentityAuthenticationContext, 'connection'>>(
    event, application, '/context', { connection: name }
  )

  return { ...value, connection: name }
}

export async function identityHostedExperience(
  event: H3Event,
  applicationKey: string
): Promise<IdentityAuthenticationExperience & { application: HostedApplicationPublicMetadata }> {
  const application = await identityHostedApplication(event, applicationKey)
  const authorization = await hostedAuthorization(event, application)
  return await hostedExperience(event, application, authorization.features.demoAccount)
}

export async function identityHostedExperienceByClient(
  event: H3Event,
  clientId: string
): Promise<IdentityAuthenticationExperience & { application: HostedApplicationPublicMetadata }> {
  const application = await identityHostedApplicationByClient(event, clientId)
  return await hostedExperience(event, application, false)
}

async function hostedExperience(event: H3Event, application: HostedApplication, demoAccountEnabled: boolean) {
  const sandboxEnabled = demoAccountEnabled && Boolean(application.sandbox)
  return {
    application: publicApplicationMetadata(event, application),
    primary: await context(event, application, 'primary'),
    sandbox: sandboxEnabled ? await context(event, application, 'sandbox') : null
  }
}

export async function identityHostedAccountContext(
  event: H3Event,
  applicationKey: string,
  intent?: string
) {
  const application = await identityHostedApplication(event, applicationKey)
  const session = await accountSession(event, applicationKey)
  let account = session.data
  let entryAuthorized = account.application === applicationKey && typeof account.entryAuthorizedAt === 'number'
  if (!entryAuthorized && intent) {
    try {
      await hostedRequest(event, application, '/account/intent/consume', { intent })
      await session.update({ application: applicationKey, connection: 'primary', entryAuthorizedAt: Date.now() })
      account = session.data
      entryAuthorized = true
    } catch {
      // Invalid, expired, or already-consumed intents are rejected by the entry guard.
    }
  }
  let authenticated = entryAuthorized && Boolean(account.identity)
  if (authenticated) {
    try {
      const currentIdentity = await identityHostedAccountRequest<{
        data: { identity: IdentityLoginData['identity'] }
      }>(event, applicationKey, '/auth/me')
      if (account.identity && currentIdentity.data.identity) {
        await session.update({
          ...account,
          identity: {
            ...account.identity,
            identity: currentIdentity.data.identity
          }
        })
        account = session.data
      }
    } catch (error) {
      if (identityErrorStatus(error) !== 401) throw error
      await session.clear()
      authenticated = false
    }
  }
  const primary = await context(event, application, 'primary')

  return {
    application: {
      key: applicationKey,
      name: application.name,
      returnUrl: hostedApplicationReturnUrl(event, application),
      authentication: application.authentication,
      sandboxEnabled: account.connection === 'sandbox'
    },
    project: primary.project,
    entryAuthorized,
    authenticated,
    authenticationMethod: authenticated ? account.authenticationMethod ?? 'password' : null,
    user: authenticated && account.identity ? account.identity.identity.user : null
  }
}

export async function identityHostedAccountLogin(
  event: H3Event,
  applicationKey: string,
  input: IdentityLoginInput
) {
  const application = await identityHostedApplication(event, applicationKey)
  const session = await accountSession(event, applicationKey)
  if (session.data.application !== applicationKey || typeof session.data.entryAuthorizedAt !== 'number') {
    throw createError({ statusCode: 403, statusMessage: 'Open account settings from your application to sign in.' })
  }
  const identity = await hostedRequest<IdentityLoginData>(event, application, '/login', input)

  if (requiresEmailVerification(identity)) {
    throw createError({
      statusCode: 403,
      statusMessage: 'Verify your email before managing this Identity account.'
    })
  }

  await session.update({
    application: applicationKey,
    connection: 'primary',
    authenticationMethod: 'password',
    entryAuthorizedAt: session.data.entryAuthorizedAt,
    identity
  })

  return await identityHostedAccountContext(event, applicationKey)
}

export async function identityHostedAccountLogout(
  event: H3Event,
  applicationKey: string
): Promise<void> {
  const session = await accountSession(event, applicationKey)
  const account = session.data
  try {
    if (account.application && account.identity?.access_token) {
      const application = await identityHostedApplication(event, applicationKey)
      const connection = account.connection === 'sandbox'
        ? assertConnection(application.sandbox)
        : application.primary
      let accessToken = account.identity.access_token
      const accessExpiresAt = Date.parse(account.identity.access_token_expires_at)
      const refreshExpiresAt = Date.parse(account.identity.refresh_token_expires_at)
      if (Number.isFinite(accessExpiresAt) && accessExpiresAt <= Date.now()
        && Number.isFinite(refreshExpiresAt) && refreshExpiresAt > Date.now()) {
        const identity = await hostedRequest<IdentityLoginData>(event, application, '/refresh', {
          refresh_token: account.identity.refresh_token
        })
        await session.update({ ...account, identity })
        accessToken = identity.access_token
      }
      await connectionRequest(connection, '/auth/logout', {
        method: 'POST',
        headers: { Authorization: `Bearer ${accessToken}` }
      })
    }
  } catch (error) {
    console.warn('[zolta-identity] Hosted account revocation failed.', {
      application: account.application,
      status: identityErrorStatus(error)
    })
  } finally {
    await session.clear()
  }
}

export async function identityHostedLogoutHandoff(
  event: H3Event,
  applicationKey: string,
  intent: string
): Promise<string> {
  const application = await identityHostedApplication(event, applicationKey)
  const result = await hostedRequest<{ redirect_url: string }>(
    event,
    application,
    '/logout/intent/consume',
    { intent }
  )
  await identityHostedAccountLogout(event, applicationKey)

  return result.redirect_url
}

export async function identityHostedAccountRequest<T>(
  event: H3Event,
  applicationKey: string,
  path: string,
  options: FetchOptions<'json'> = {}
): Promise<T> {
  const application = await identityHostedApplication(event, applicationKey)
  const session = await accountSession(event, applicationKey)
  const account = session.data

  if (account.application !== applicationKey || !account.identity?.access_token) {
    throw createError({ statusCode: 401, statusMessage: 'Sign in to manage this Identity account.' })
  }
  const accountIdentity = account.identity

  const connection = account.connection === 'sandbox'
    ? assertConnection(application.sandbox)
    : application.primary
  const execute = (token: string) => connectionRequest<T>(connection, path, {
    ...options,
    headers: {
      Authorization: `Bearer ${token}`,
      ...options.headers
    }
  })
  const refresh = async () => {
    const identity = await hostedRequest<IdentityLoginData>(event, application, '/refresh', {
      refresh_token: accountIdentity.refresh_token
    })
    await session.update({ ...account, identity })
    return identity.access_token
  }

  try {
    const expiresAt = Date.parse(accountIdentity.access_token_expires_at)
    const token = Number.isFinite(expiresAt) && expiresAt <= Date.now() + 30_000
      ? await refresh()
      : accountIdentity.access_token
    return await execute(token)
  } catch (error) {
    const status = (error as { response?: { status?: number } }).response?.status
    if (status !== 401) throw error

    try {
      return await execute(await refresh())
    } catch (refreshError) {
      await session.clear()
      throw refreshError
    }
  }
}

export async function identityHostedAccountUpdateSessionUser(
  event: H3Event,
  applicationKey: string,
  user: Pick<IdentityLoginData['identity']['user'], 'username' | 'email' | 'avatar_url'>
): Promise<void> {
  const session = await accountSession(event, applicationKey)
  const account = session.data

  if (account.application !== applicationKey || !account.identity) {
    return
  }

  await session.update({
    ...account,
    identity: {
      ...account.identity,
      identity: {
        ...account.identity.identity,
        user: {
          ...account.identity.identity.user,
          ...user
        }
      }
    }
  })
}

export async function requireIdentityHostedAccountPasswordAuthentication(
  event: H3Event,
  applicationKey: string
): Promise<void> {
  const account = await accountSession(event, applicationKey)
  if (account.data.application !== applicationKey || !account.data.identity) {
    throw createError({ statusCode: 401, statusMessage: 'Sign in to manage this Identity account.' })
  }
  if (account.data.authenticationMethod === 'google') {
    throw createError({
      statusCode: 403,
      statusMessage: 'Password management is unavailable for an account session authenticated with Google.'
    })
  }
}

export async function identityHostedLogin(
  event: H3Event,
  applicationKey: string,
  state: string,
  input: IdentityLoginInput
) {
  const application = await identityHostedApplication(event, applicationKey)
  assertHostedPrimaryAuthenticationAllowed(application)
  const identity = await hostedRequest<IdentityLoginData>(event, application, '/login', input)

  if (requiresEmailVerification(identity)) {
    const session = await flowSession(event)
    await session.update({ application: applicationKey, state: assertState(state), identity })
    const verificationRoute = identityHostedAuthPageRoute(application, 'verify-email')
    const verificationUrl = new URL(verificationRoute, getRequestURL(event).origin)
    verificationUrl.searchParams.set('application', applicationKey)
    verificationUrl.searchParams.set('state', state)

    return { redirectUrl: verificationUrl.toString() }
  }

  return await handoff(event, application, identity, state)
}

export async function identityHostedSandbox(
  event: H3Event,
  applicationKey: string,
  state: string,
  termsAccepted = false
) {
  const application = await identityHostedApplication(event, applicationKey)
  const authorization = await requireHostedAuthorization(event, application, state)
  if (!authorization.features.demoAccount) {
    throw createError({ statusCode: 403, statusMessage: 'Demo sandbox accounts are disabled for this application.' })
  }
  if (application.authentication.termsRequired && !termsAccepted) {
    throw createError({ statusCode: 422, statusMessage: 'Accept the current application policies before creating a demo account.' })
  }
  assertConnection(application.sandbox)
  const identity = await hostedRequest<IdentityLoginData>(event, application, '/sandbox-session', {
    terms_accepted: termsAccepted
  })

  return await handoff(event, application, identity, state)
}

export async function identityHostedRegister(
  event: H3Event,
  applicationKey: string,
  state: string,
  input: IdentityRegisterInput
) {
  const application = await identityHostedApplication(event, applicationKey)
  assertHostedPrimaryAuthenticationAllowed(application)
  if (application.authentication.termsRequired && input.termsAccepted !== true) {
    throw createError({ statusCode: 422, statusMessage: 'Accept the current application policies before creating an account.' })
  }
  const identity = await hostedRequest<IdentityLoginData>(event, application, '/register', {
    username: input.username,
    email: input.email,
    password: input.password,
    password_confirmation: input.passwordConfirmation,
    terms_accepted: input.termsAccepted
  })

  if (!requiresEmailVerification(identity)) {
    return await handoff(event, application, identity, state)
  }

  const session = await flowSession(event)
  await session.update({ application: applicationKey, state: assertState(state), identity })

  return { verificationRequired: true }
}

export async function identityHostedGoogleStart(
  event: H3Event,
  applicationKey: string,
  state: string,
  termsAccepted = false
) {
  const application = await identityHostedApplication(event, applicationKey)
  const hostedEntry = await requireHostedAuthorization(event, application, state)
  assertHostedPrimaryAuthenticationAllowed(application)
  const google = useRuntimeConfig(event).identityGoogle
  if (hostedEntry.features.demoAccount && application.sandbox) {
    throw createError({ statusCode: 403, statusMessage: 'Google sign-in is disabled while the demo sandbox is enabled.' })
  }
  if (!application.authentication.googleEnabled || !google?.clientId || !google?.clientSecret) {
    throw createError({ statusCode: 404, statusMessage: 'Google sign-in is not available for this application.' })
  }
  if (application.authentication.termsRequired && !termsAccepted) {
    throw createError({ statusCode: 422, statusMessage: 'Accept the current application policies before continuing with Google.' })
  }
  const providerState = crypto.randomUUID().replaceAll('-', '')
  const session = await socialFlowSession(event)
  await session.update({
    application: applicationKey,
    state: assertState(state),
    providerState,
    purpose: 'authentication',
    termsAccepted
  })
  const redirectUri = new URL('/api/hosted-auth/google/callback', getRequestURL(event).origin).toString()
  const authorization = new URL('https://accounts.google.com/o/oauth2/v2/auth')
  authorization.searchParams.set('client_id', google.clientId)
  authorization.searchParams.set('redirect_uri', redirectUri)
  authorization.searchParams.set('response_type', 'code')
  authorization.searchParams.set('scope', 'openid email profile')
  authorization.searchParams.set('state', providerState)
  authorization.searchParams.set('prompt', 'select_account')
  return { redirectUrl: authorization.toString() }
}

export async function identityHostedAccountGoogleStart(
  event: H3Event,
  applicationKey: string,
  tab: 'profile' | 'security'
) {
  const application = await identityHostedApplication(event, applicationKey)
  const account = await accountSession(event, applicationKey)
  if (account.data.application !== applicationKey || typeof account.data.entryAuthorizedAt !== 'number') {
    throw createError({ statusCode: 403, statusMessage: 'Open account settings from your application to sign in.' })
  }
  if (account.data.connection === 'sandbox') {
    throw createError({ statusCode: 403, statusMessage: 'Google sign-in is disabled while the demo sandbox is enabled.' })
  }
  const google = useRuntimeConfig(event).identityGoogle
  if (!application.authentication.googleEnabled || !google?.clientId || !google?.clientSecret) {
    throw createError({ statusCode: 404, statusMessage: 'Google sign-in is not available for this application.' })
  }
  const providerState = crypto.randomUUID().replaceAll('-', '')
  const session = await socialFlowSession(event)
  await session.update({ application: applicationKey, providerState, purpose: 'account', tab })
  const redirectUri = new URL('/api/hosted-auth/google/callback', getRequestURL(event).origin).toString()
  const authorization = new URL('https://accounts.google.com/o/oauth2/v2/auth')
  authorization.searchParams.set('client_id', google.clientId)
  authorization.searchParams.set('redirect_uri', redirectUri)
  authorization.searchParams.set('response_type', 'code')
  authorization.searchParams.set('scope', 'openid email profile')
  authorization.searchParams.set('state', providerState)
  authorization.searchParams.set('prompt', 'select_account')
  return { redirectUrl: authorization.toString() }
}

export async function identityHostedGoogleCallback(event: H3Event, code: string, providerState: string) {
  const session = await socialFlowSession(event)
  const flow = session.data
  if (!flow.application || !flow.purpose || !flow.providerState || flow.providerState !== providerState) {
    throw createError({ statusCode: 401, statusMessage: 'The Google sign-in request has expired. Please try again.' })
  }
  const application = await identityHostedApplication(event, flow.application)
  if (flow.purpose === 'authentication') {
    assertHostedPrimaryAuthenticationAllowed(application)
  }
  const google = useRuntimeConfig(event).identityGoogle
  const redirectUri = new URL('/api/hosted-auth/google/callback', getRequestURL(event).origin).toString()
  const response = await $fetch<{ access_token?: string }>('https://oauth2.googleapis.com/token', {
    method: 'POST',
    body: {
      code,
      client_id: google.clientId,
      client_secret: google.clientSecret,
      redirect_uri: redirectUri,
      grant_type: 'authorization_code'
    }
  })
  if (!response.access_token) throw createError({ statusCode: 401, statusMessage: 'Google did not return an access token.' })
  const identity = await hostedRequest<IdentityLoginData>(event, application, '/social', {
    provider: 'google',
    access_token: response.access_token,
    terms_accepted: flow.purpose === 'authentication' && flow.termsAccepted === true
  })
  if (flow.purpose === 'account') {
    const account = await accountSession(event, flow.application)
    if (account.data.application !== flow.application || typeof account.data.entryAuthorizedAt !== 'number') {
      throw createError({ statusCode: 403, statusMessage: 'The account settings request has expired. Please start again.' })
    }
    await account.update({
      application: flow.application,
      connection: 'primary',
      authenticationMethod: 'google',
      entryAuthorizedAt: account.data.entryAuthorizedAt,
      identity
    })
    const redirect = new URL('/account', getRequestURL(event).origin)
    redirect.searchParams.set('application', flow.application)
    redirect.searchParams.set('tab', flow.tab ?? 'profile')
    await session.clear()
    return { redirectUrl: redirect.toString() }
  }
  if (!flow.state) {
    throw createError({ statusCode: 401, statusMessage: 'The Google sign-in request has expired. Please try again.' })
  }
  const result = await handoff(event, application, identity, flow.state)
  await session.clear()
  return result
}

export async function identityHostedResendVerification(event: H3Event) {
  const session = await flowSession(event)
  const flow = session.data
  if (!flow.application || !flow.identity) {
    throw createError({ statusCode: 401, statusMessage: 'The hosted verification flow has expired.' })
  }
  const application = await identityHostedApplication(event, flow.application)

  return await clientRequest<Record<string, unknown>>(
    application.primary,
    '/auth/email/verification/resend',
    {},
    flow.identity.access_token
  )
}

export async function identityHostedFlowContext(event: H3Event) {
  const session = await flowSession(event)
  const flow = session.data
  if (!flow.application || !flow.state || !flow.identity) {
    throw createError({ statusCode: 401, statusMessage: 'The hosted verification flow has expired.' })
  }

  return {
    application: flow.application,
    state: flow.state,
    email: flow.identity.identity.user.email
  }
}

export async function identityHostedVerifyEmail(event: H3Event, code: string) {
  const session = await flowSession(event)
  const flow = session.data
  if (!flow.application || !flow.state || !flow.identity) {
    throw createError({ statusCode: 401, statusMessage: 'The hosted verification flow has expired.' })
  }
  const application = await identityHostedApplication(event, flow.application)
  await clientRequest<Record<string, unknown>>(
    application.primary,
    '/auth/email/verification',
    { code },
    flow.identity.access_token
  )
  const result = await handoff(event, application, flow.identity, flow.state)
  await session.clear()

  return result
}

export async function identityHostedForgotPassword(
  event: H3Event,
  applicationKey: string,
  email: string
) {
  const application = await identityHostedApplication(event, applicationKey)
  assertHostedPrimaryAuthenticationAllowed(application)
  try {
    return await hostedRequest<Record<string, unknown>>(event, application, '/password/forgot', { email })
  } catch (error) {
    if (error instanceof FetchError && error.response?.status === 429) {
      const rawRetryAfter = Number(error.response.headers.get('Retry-After'))
      const retryAfter = Number.isInteger(rawRetryAfter) && rawRetryAfter > 0 && rawRetryAfter <= 3600 ? rawRetryAfter : 60
      setResponseHeader(event, 'Retry-After', retryAfter)
      throw createError({
        statusCode: 429,
        statusMessage: 'Too many password reset requests. Please wait before trying again.',
        data: { retryAfter }
      })
    }
    throw error
  }
}

export async function identityHostedResetPassword(
  event: H3Event,
  clientId: string,
  input: IdentityResetPasswordInput
) {
  // The Identity Console uses the shared auth layer without being a hosted
  // application. Its reset links still carry the configured client id, so
  // route those links through the first-party client contract instead of
  // attempting a hosted-application lookup.
  if (clientId === identityConfiguration(event).clientId) {
    return await identityResetPassword(event, input)
  }

  const application = await identityHostedApplicationByClient(event, clientId)
  assertHostedPrimaryAuthenticationAllowed(application)
  const result = await hostedRequest<Record<string, unknown>>(event, application, '/password/reset', {
    email: input.email,
    token: input.token,
    password: input.password,
    password_confirmation: input.passwordConfirmation
  })

  return {
    ...result,
    applicationUrl: hostedApplicationReturnUrl(event, application)
  }
}

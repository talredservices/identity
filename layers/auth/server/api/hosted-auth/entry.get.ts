import {
  defineEventHandler,
  getRequestURL,
  getValidatedQuery,
  sendRedirect,
  setResponseHeader
} from 'h3'
import { z } from 'zod/v4'
import { establishIdentityHostedAuthorization, identityHostedAuthPageRoute } from '../../utils/identity-hosted-auth'

const schema = z.object({
  application: z.string().trim().min(1).max(100),
  state: z.string().regex(/^[A-Za-z0-9_-]{32,180}$/),
  intent: z.string().trim().min(64).max(180),
  screen: z.enum(['login', 'register', 'forgot-password', 'reset-password']),
  email: z.email().max(254).optional(),
  token: z.string().trim().min(64).max(512).optional()
})

export default defineEventHandler(async (event) => {
  setResponseHeader(event, 'cache-control', 'no-store')
  setResponseHeader(event, 'referrer-policy', 'no-referrer')

  const query = await getValidatedQuery(event, schema.parse)
  const application = await establishIdentityHostedAuthorization(
    event,
    query.application,
    query.intent,
    query.state
  )

  const pageRoute = identityHostedAuthPageRoute(application, query.screen)
  const redirect = new URL(pageRoute, getRequestURL(event).origin)
  redirect.searchParams.set('application', query.application)
  redirect.searchParams.set('state', query.state)
  if (query.email) redirect.searchParams.set('email', query.email)
  if (query.token) redirect.searchParams.set('token', query.token)

  return sendRedirect(event, `${redirect.pathname}${redirect.search}`, 302)
})

import { createError, defineEventHandler, getRouterParam, readValidatedBody } from 'h3'
import { z } from 'zod/v4'
import type { IdentityHostedApplication } from '#admin/types/identity-access'

const appearanceSchema = z.object({
  welcome_text: z.string().trim().max(280).nullable(),
  accent_color: z.string().regex(/^#[0-9A-Fa-f]{6}$/).nullable(),
  background_preset: z.enum(['identity', 'slate', 'indigo', 'emerald', 'sunset'])
})
const authenticationSchema = z.object({
  google_enabled: z.boolean(),
  demo_only: z.boolean(),
  terms_required: z.boolean(),
  terms_url: z.url().max(2048).nullable(),
  privacy_url: z.url().max(2048).nullable()
})

const schema = z.object({
  name: z.string().trim().min(2).max(200),
  key: z.string().trim().regex(/^[A-Za-z0-9_-]+$/).max(100),
  primary_client_id: z.uuid(),
  sandbox_client_id: z.uuid().nullable(),
  application_url: z.url().max(2048),
  callback_url: z.url().max(2048),
  auth_page_set: z.string().regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/).max(100),
  appearance: appearanceSchema,
  authentication: authenticationSchema
}).refine(body => !body.authentication.terms_required || body.authentication.terms_url !== null, {
  message: 'A Terms of Service URL is required when terms acceptance is enabled.',
  path: ['authentication', 'terms_url']
}).refine(body => !body.authentication.demo_only || body.sandbox_client_id !== null, {
  message: 'A sandbox client is required when demo-only sign-in is enabled.',
  path: ['sandbox_client_id']
})

export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'id')
  if (!id) throw createError({ statusCode: 400, statusMessage: 'Project ID is required.' })
  const body = await readValidatedBody(event, schema.parse)
  const response = await identityApi<{ data: IdentityHostedApplication }>(
    event,
    `/api/v1/identity/projects/${id}/hosted-applications`,
    { method: 'POST', body }
  )
  return response.data
})

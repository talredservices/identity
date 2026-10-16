import { createError, defineEventHandler, getRouterParam, readValidatedBody } from 'h3'
import { z } from 'zod/v4'
import type { IdentityClient } from '#admin/types/identity-access'

export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'id')
  const client = getRouterParam(event, 'client')
  if (!id || !client) throw createError({ statusCode: 400, statusMessage: 'Project and client IDs are required.' })
  const body = await readValidatedBody(event, z.object({ name: z.string().trim().min(2).max(255) }).parse)
  const response = await identityApi<{ data: IdentityClient }>(event, `/api/v1/identity/projects/${id}/clients/${client}/name`, { method: 'PATCH', body })
  return response.data
})

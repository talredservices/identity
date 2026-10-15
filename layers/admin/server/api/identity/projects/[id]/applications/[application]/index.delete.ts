import { createError, defineEventHandler, getRouterParam } from 'h3'

export default defineEventHandler(async (event) => {
  const id = getRouterParam(event, 'id')
  const application = getRouterParam(event, 'application')
  if (!id || !application) throw createError({ statusCode: 400, statusMessage: 'Hosted application ID is required.' })
  return await identityApi(event, `/api/v1/identity/projects/${id}/applications/${application}`, {
    method: 'DELETE'
  })
})

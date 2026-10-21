import { defineEventHandler, readValidatedBody } from 'h3'
import { z } from 'zod/v4'
import { identityHostedGoogleStart } from '../../utils/identity-hosted-auth'

const schema = z.object({
  application: z.string().trim().min(1).max(100),
  state: z.string().min(32).max(180),
  termsAccepted: z.boolean().optional()
})

export default defineEventHandler(async (event) => {
  const body = await readValidatedBody(event, schema.parse)
  return await identityHostedGoogleStart(event, body.application, body.state, body.termsAccepted === true)
})

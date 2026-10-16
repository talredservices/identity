import type { IdentityHostedApplication } from '../../types/identity-access'

export function identityHostedApplicationPublicUrl(application: IdentityHostedApplication) {
  return new URL(application.application_url).toString()
}

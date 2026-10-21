export type IdentityBrowserSession = {
  projectId: string
  projectName: string
  projectSlug: string
  clientId: string
  membershipId: string
  isProjectAdmin: boolean
  isSystemAdmin: boolean
  roles: string[]
  permissions: string[]
  authorizationVersion: number
  accessTokenExpiresAt: string
  isTemporary: boolean
  temporaryExpiresAt: string | null
}

export type IdentityConnectionName = 'primary' | 'sandbox'

export type IdentityAuthenticationContext = {
  connection: IdentityConnectionName
  project: {
    id: string
    name: string
    slug: string
    description: string | null
    status: 'active' | 'disabled'
    mode: 'live' | 'sandbox'
    sandbox_ttl_minutes: number
    registration_mode: 'invite_only' | 'public'
    registration_role_id: string | null
    email_verification_required: boolean
  }
  client: {
    id: string
    name: string
  }
}

export type IdentityHostedApplicationMetadata = {
  key: string
  name: string
  projectName: string
  returnUrl: string
  presentation: {
    pageSet: string
    productName: string
    brandName: string | null
    brandBadge: string | null
    welcomeText: string | null
    accentColor: string | null
    backgroundPreset: 'identity' | 'slate' | 'indigo' | 'emerald' | 'sunset'
    logoUrl: string | null
    designTokens: Record<string, string>
  }
  authentication: {
    googleEnabled: boolean
    demoOnly: boolean
    termsRequired: boolean
    termsUrl: string | null
    privacyUrl: string | null
  }
}

export type IdentityAuthenticationExperience = {
  primary: IdentityAuthenticationContext
  sandbox: IdentityAuthenticationContext | null
  application?: IdentityHostedApplicationMetadata
}

export type IdentityAccountSession = {
  id: string
  current: boolean
  project: {
    id: string
    name: string
    slug: string
  } | null
  client: {
    id: string
    name: string
  } | null
  created_at: string | null
  expires_at: string
}

export type IdentityLoginData = {
  access_token: string
  access_token_expires_at: string
  refresh_token: string
  refresh_token_expires_at: string
  identity: {
    user: {
      id: string
      email: string
      username: string
      avatar_url: string | null
      email_verified: boolean
      is_system_admin: boolean
      is_temporary: boolean
      temporary_expires_at: string | null
    }
    project: {
      id: string
      name: string
      slug: string
      mode: 'live' | 'sandbox'
      sandbox_ttl_minutes: number
      registration_mode: 'invite_only' | 'public'
      registration_role_id: string | null
      email_verification_required: boolean
    }
    client: { id: string }
    membership: {
      id: string
      is_admin: boolean
      roles: string[]
      permissions: string[]
      authorization_version: number
    }
  }
}

export type IdentitySandboxSessionData = IdentityLoginData & {
  is_temporary: true
  expires_at: string
}

export type IdentityLoginInput = {
  email: string
  password: string
}

export type IdentityRegisterInput = {
  username: string
  email: string
  password: string
  passwordConfirmation: string
  termsAccepted?: boolean
}

export type IdentityResetPasswordInput = {
  email: string
  token: string
  password: string
  passwordConfirmation: string
}

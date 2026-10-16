export function createAuthRuntimeConfig(
  env: NodeJS.ProcessEnv = process.env
) {
  return {
    session: {
      password: env.NUXT_SESSION_PASSWORD,
      name: env.IDENTITY_SESSION_COOKIE_NAME ?? 'identity-session',
      cookie: {
        httpOnly: true,
        sameSite: 'lax',
        secure: env.NODE_ENV === 'production',
        path: '/',
        maxAge: 60 * 60 * 24 * 7
      }
    },
    identity: {
      apiUrl: env.IDENTITY_API_URL ?? env.LARAVEL_API_URL ?? 'http://localhost:8000',
      project: env.IDENTITY_PROJECT ?? '',
      clientId: env.IDENTITY_CLIENT_ID ?? '',
      clientSecret: env.IDENTITY_CLIENT_SECRET ?? '',
      sandbox: {
        apiUrl: env.IDENTITY_SANDBOX_API_URL ?? env.IDENTITY_API_URL ?? env.LARAVEL_API_URL ?? 'http://localhost:8000',
        project: env.IDENTITY_SANDBOX_PROJECT ?? '',
        clientId: env.IDENTITY_SANDBOX_CLIENT_ID ?? '',
        clientSecret: env.IDENTITY_SANDBOX_CLIENT_SECRET ?? ''
      }
    },
    identityHostedApplicationsToken: env.IDENTITY_HOSTED_APPLICATIONS_TOKEN ?? '',
    identityHostedAuthPageSets: (env.IDENTITY_HOSTED_AUTH_PAGE_SETS ?? 'default')
      .split(',')
      .map(pageSet => pageSet.trim())
      .filter(Boolean),
    identityGoogle: {
      clientId: env.IDENTITY_GOOGLE_CLIENT_ID ?? env.GOOGLE_CLIENT_ID ?? '',
      clientSecret: env.IDENTITY_GOOGLE_CLIENT_SECRET ?? env.GOOGLE_CLIENT_SECRET ?? ''
    },
    public: {
      identityAuth: {
        productName: env.NUXT_PUBLIC_IDENTITY_AUTH_PRODUCT_NAME ?? 'Identity',
        loginRedirect: env.NUXT_PUBLIC_IDENTITY_AUTH_LOGIN_REDIRECT ?? '/',
        logoutRedirect: env.NUXT_PUBLIC_IDENTITY_AUTH_LOGOUT_REDIRECT ?? '/auth/login',
        registerRedirect: env.NUXT_PUBLIC_IDENTITY_AUTH_REGISTER_REDIRECT ?? '/auth/verify-email',
        sandboxEnabled: env.NUXT_PUBLIC_IDENTITY_SANDBOX_ENABLED === 'true',
        portfolioProductsUrl: env.IDENTITY_PORTFOLIO_PRODUCTS_URL ?? ''
      }
    }
  }
}

export function createAuthCsurfConfig(
  env: NodeJS.ProcessEnv = process.env
) {
  return {
    headerName: 'csrf-token',
    cookieKey: env.IDENTITY_CSRF_COOKIE_NAME ?? 'identity-csrf',
    cookie: {
      path: '/',
      httpOnly: true,
      sameSite: 'lax' as const,
      secure: env.NODE_ENV === 'production'
    }
  }
}

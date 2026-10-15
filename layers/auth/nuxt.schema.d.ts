declare module '@nuxt/schema' {
  interface PublicRuntimeConfig {
    identityAdmin: {
      publicHost: string
    }
    identityAuth: {
      productName: string
      loginRedirect: string
      logoutRedirect: string
      registerRedirect: string
      sandboxEnabled: boolean
      portfolioProductsUrl: string
    }
  }

  interface RuntimeConfig {
    admin: {
      publicHost: string
      host: string
    }
    identity: {
      apiUrl: string
      project: string
      clientId: string
      clientSecret: string
      sandbox: {
        apiUrl: string
        project: string
        clientId: string
        clientSecret: string
      }
    }
    identityHostedApplicationsToken: string
    identityGoogle: { clientId: string, clientSecret: string }
  }
}

export {}

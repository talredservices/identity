import { adminAliases } from './index'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  alias: {
    ...adminAliases
  },
  runtimeConfig: {
    admin: {
      publicHost: process.env.NUXT_ADMIN_PUBLIC_HOST ?? '',
      host: process.env.NUXT_ADMIN_HOST ?? ''
    },
    public: {
      identityAdmin: {
        publicHost: process.env.NUXT_PUBLIC_IDENTITY_ADMIN_PUBLIC_HOST ?? ''
      }
    }
  }
})

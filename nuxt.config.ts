const viteAllowedHosts = [
  'localhost',
  '127.0.0.1',
  ...(process.env.NUXT_VITE_ALLOWED_HOSTS ?? '')
    .split(',')
    .map(host => host.trim())
    .filter(Boolean)
]

const hostedAuthIntegrations = (process.env.IDENTITY_HOSTED_AUTH_INTEGRATIONS ?? '')
  .split(',')
  .map(path => path.trim())
  .filter(Boolean)

export default defineNuxtConfig({
  extends: [
    './layers/auth/default-pages',
    ...hostedAuthIntegrations,
    './layers/admin',
    './packages/i18n',
    './packages/ui'
  ],
  modules: ['@nuxt/eslint', '@vueuse/nuxt'],
  typescript: {
    appTsConfig: {
      compilerOptions: {
        paths: {
          '@nuxt/ui': ['../node_modules/@nuxt/ui'],
          vue: ['../node_modules/vue']
        }
      }
    }
  },
  icon: {
    clientBundle: {
      icons: ['lucide:languages'],
      scan: true
    }
  },
  i18n: {
    locales: [
      {
        code: 'en',
        name: 'English',
        file: 'en.json'
      },
      {
        code: 'fr',
        name: 'Français',
        file: 'fr.json'
      }
    ],
    defaultLocale: 'en',
    langDir: 'locales'
  },
  compatibilityDate: '2025-07-15',
  devtools: { enabled: process.env.NODE_ENV === 'development' },
  devServer: {
    port: 3100
  },
  nitro: {
    prerender: {
      routes: [],
      crawlLinks: false,
      failOnError: true
    }
  },
  eslint: {
    config: {
      stylistic: {
        indent: 2,
        quotes: 'single',
        semi: false,
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  },
  vite: {
    plugins: [],
    optimizeDeps: {
      // The i18n runtime imports Nuxt-generated #components aliases. The
      // dependency scanner treats those imports as package exports before
      // Nuxt's alias plugin runs, so let Vite transform i18n at request time.
      exclude: ['@nuxtjs/i18n']
    },
    server: {
      allowedHosts: viteAllowedHosts,
      watch: {
        ignored: ['**/docs/**', '**/storage/**', '**/.cache/**', '**/tmp/**']
      }
    }
  }
})

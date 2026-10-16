# @talred/identity-nuxt

Reusable server-side Nuxt authentication for Laravel Talred Identity.

## Choose an entry

```ts
// Ready-made /auth/* pages
export default defineNuxtConfig({
  extends: ['@talred/identity-nuxt/default-pages']
})
```

```ts
// Headless integration for custom application pages
export default defineNuxtConfig({
  extends: ['@talred/identity-nuxt']
})
```

The headless entry provides:

- encrypted HTTP-only sessions through `nuxt-auth-utils`
- CSRF protection for browser mutations
- local login, registration, logout, recovery, reset, verification, and
  session-management BFF routes under `/api/auth/*`
- rotating-token refresh with concurrent-refresh deduplication
- `useIdentityAuth()` for custom pages
- `identity-auth` route middleware for protected pages
- `@talred/identity-nuxt/types` for browser-safe identity types

For a consumer that redirects to Identity-hosted pages, do not extend the full
layer. Import server-only session and token helpers from
`@talred/identity-nuxt/server`; this avoids mounting embedded credential
pages and `/api/auth/login|register|reset` handlers in the consumer.

The Identity host resolves hosted applications from the Identity Console using
its server-only `IDENTITY_HOSTED_APPLICATIONS_TOKEN`. Configure the application
name, return URL, exact callback URL, live BFF client, and optional sandbox BFF
client in the Console. The hosted application record never stores client
secrets.

Each hosted application also controls its own sign-in presentation: Google
sign-in, an optional required terms checkbox, and Terms of Service and Privacy
Policy links. Enable Google only after configuring
`IDENTITY_GOOGLE_CLIENT_ID` and `IDENTITY_GOOGLE_CLIENT_SECRET` on the Identity
Nuxt host and registering `<identity-host>/api/hosted-auth/google/callback` in
Google Cloud. When terms are required, Identity records the accepted terms URL
when it creates the application's membership; existing members can still use
Google to sign in without re-accepting.

The ready-made hosted pages include the optional
`IdentityAttribution.vue` lower-left Talred credit and source link. A cloned
or white-labelled host can remove `<IdentityAttribution />` from
`layers/auth/default-pages/app/layouts/identity-auth.vue`.
Set `IDENTITY_PORTFOLIO_PRODUCTS_URL` to show the optional “Built by Redouane”
link; it should use the deployed portfolio products URL in production.

The default-pages entry also mounts `/account?application=<key>`. This hosted
account portal signs the user in against that application's confidential BFF
client and owns global profile, password, session, export, and deletion actions.
The consumer only links to the portal, so account credentials and account-level
security payloads never traverse the consumer BFF. The return destination comes
from the registered `applicationUrl`, not from a browser-supplied URL.

## Server configuration

```dotenv
NUXT_SESSION_PASSWORD=<at-least-32-random-characters>
IDENTITY_API_URL=http://127.0.0.1:8100
IDENTITY_PROJECT=<project-slug-or-id>
IDENTITY_CLIENT_ID=<nuxt-bff-client-id>
IDENTITY_CLIENT_SECRET=<nuxt-bff-client-secret>
```

Credentialless demos use a separate project configured as `sandbox` in the Identity Console:

```dotenv
IDENTITY_SANDBOX_API_URL=http://127.0.0.1:8100
IDENTITY_SANDBOX_PROJECT=<sandbox-project-slug-or-id>
IDENTITY_SANDBOX_CLIENT_ID=<sandbox-nuxt-bff-client-id>
IDENTITY_SANDBOX_CLIENT_SECRET=<sandbox-nuxt-bff-client-secret>
NUXT_PUBLIC_IDENTITY_SANDBOX_ENABLED=true
```

`useIdentityAuth().createSandboxSession()` calls the local `/api/auth/sandbox-session` route. Identity creates a verified temporary user without a password or verification email. The encrypted BFF session remembers the selected connection so refresh and logout use the sandbox client.

The ready-made pages resolve their experience from Identity at runtime:

- a live primary project renders sign-in, public registration, email
  verification, password recovery, and password reset
- a hosted consumer may enable the **Create instant demo account** action per
  application; Identity carries that client-authenticated policy in a one-time
  authorization intent and exposes it only when the hosted application has a
  valid sandbox client
- a sandbox primary project hides permanent-account forms, immediately creates a
  temporary identity, and shows its generated name, email, and expiry before the
  user continues

Client secrets and issued tokens never enter the rendered page. Applications can
still extend the headless entry and supply custom pages while keeping the same
Identity APIs and encrypted BFF session behavior.

The API URL may be the server origin or include `/api/v1/identity`. Tokens and
client credentials remain private runtime configuration.

Optional settings:

```dotenv
IDENTITY_SESSION_COOKIE_NAME=my-app-session
IDENTITY_CSRF_COOKIE_NAME=my-app-csrf
NUXT_PUBLIC_IDENTITY_AUTH_PRODUCT_NAME="My application"
NUXT_PUBLIC_IDENTITY_AUTH_LOGIN_REDIRECT=/dashboard
NUXT_PUBLIC_IDENTITY_AUTH_LOGOUT_REDIRECT=/auth/login
NUXT_PUBLIC_IDENTITY_AUTH_REGISTER_REDIRECT=/auth/verify-email
```

Only local redirect paths are accepted by the default pages.

## Custom page example

```vue
<script setup lang="ts">
const { login } = useIdentityAuth()
const form = reactive({ email: '', password: '' })

async function submit() {
  await login(form)
  await navigateTo('/dashboard')
}
</script>

<template>
  <form @submit.prevent="submit">
    <input v-model="form.email" type="email">
    <input v-model="form.password" type="password">
    <button type="submit">Sign in</button>
  </form>
</template>
```

Consumers may keep application-specific user fields and permission mapping in a
thin server adapter. The package preserves existing user and secure-session
fields when it rotates identity tokens.

This BFF package does not create cross-application SSO. Authorization code plus
PKCE/OIDC remains a possible standards-based protocol for broader federation.
The hosted authentication integration uses a confidential-client exchange of a
short-lived, callback-bound, single-use handoff code; credentials and tokens
remain inside Identity and the consumer BFF. Hosted account management uses a
separate, one-hour encrypted HTTP-only Identity session and does not expose its
access token to the browser or consumer application.

## Configurable hosted authentication

Authentication and account pages stay on the public Identity host and use the
existing authorization, callback, account-session, and API handshakes. The
generic form reads the hosted application's name and validated appearance
metadata from Identity. Identity source contains no consumer application-key
registry or consumer brand implementation.

The hosted application may select an `auth_page_set` slug. A host only routes
to that slug when it is enabled in `IDENTITY_HOSTED_AUTH_PAGE_SETS` and a
corresponding layer is compiled into that host. Missing, invalid, and disabled
values use the generic `/auth/*` routes. Consumer-owned page layers and their
brand configuration belong in the consumer's integration bundle; Identity can
include an optional bundle through the generic
`IDENTITY_HOSTED_AUTH_INTEGRATIONS` build setting.
The Nuxt build-time default allowlist comes from
`IDENTITY_HOSTED_AUTH_PAGE_SETS`; a built host can override its runtime list
with the comma-separated `NUXT_IDENTITY_HOSTED_AUTH_PAGE_SETS` variable.

Appearance is data on the hosted application, not a source-code lookup by app
key. Identity validates the optional welcome text, accent, background preset,
and design tokens before returning public metadata. The generic layout renders
the configured application name, logo when configured, and sanitized theme.
Never put client secrets or callback credentials in appearance metadata.

Reset mail uses `IDENTITY_PASSWORD_RESET_URL` from the Identity installation
as the trusted public reset base. It appends a page-set path only when the
hosted application's configured slug is enabled by the API host; otherwise it
uses the generic reset page. Each isolated Identity installation must set its
own public reset host and keep the API and Nuxt page-set allowlists aligned.

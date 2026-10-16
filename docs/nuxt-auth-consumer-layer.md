# Nuxt authentication consumer layer

The identity repository owns a reusable Nuxt BFF authentication package. Consumer applications keep their identity client secret and access tokens on their own server; browsers only receive an encrypted, HTTP-only application session.

## Package entries

- `@talred/identity-nuxt` provides the headless authentication layer: the encrypted session, CSRF protection, identity API client, local `/api/auth/*` routes, composables, middleware, and reusable form components.
- `@talred/identity-nuxt/default-pages` extends the headless layer with ready-to-use login, registration, password recovery/reset, and email verification pages.

An application with branded authentication pages extends the headless entry and builds its pages from `useIdentityAuth` or the supplied components. An application that wants the standard interface extends `default-pages`. A consumer may also override any default Nuxt page locally.

## Responsibility boundaries

The identity service owns credentials, password recovery, email verification, global profile fields, sessions, projects, clients, users, roles, and permissions. Consumer applications own business data and business authorization decisions. They may map identity permissions to application-specific capabilities, but must not reimplement credential validation or token refresh.

The package is a BFF integration, not browser-to-identity authentication. Client secrets and bearer tokens never enter browser JavaScript.

## Required environment variables

```dotenv
NUXT_SESSION_PASSWORD=<at-least-32-random-characters>
IDENTITY_API_URL=http://127.0.0.1:8100/api/v1/identity
IDENTITY_PROJECT=<project-slug-or-id>
IDENTITY_CLIENT_ID=<the-consumer-bff-client-id>
IDENTITY_CLIENT_SECRET=<the-consumer-bff-client-secret>
```

Use one confidential BFF client per application and environment. Do not reuse an API client as the BFF client.

For demos, create a separate sandbox project with separate BFF and API clients. Configure the BFF with `IDENTITY_SANDBOX_API_URL`, `IDENTITY_SANDBOX_PROJECT`, `IDENTITY_SANDBOX_CLIENT_ID`, and `IDENTITY_SANDBOX_CLIENT_SECRET`, then call `createSandboxSession()`. Sandbox sessions do not expose browser credentials and expire at the TTL configured on the project.

Cookie names are configurable when applications share a hostname:

```dotenv
IDENTITY_SESSION_COOKIE_NAME=my-app-session
IDENTITY_CSRF_COOKIE_NAME=my-app-csrf
```

The default pages accept optional public presentation settings:

```dotenv
NUXT_PUBLIC_IDENTITY_AUTH_PRODUCT_NAME="My application"
NUXT_PUBLIC_IDENTITY_AUTH_LOGIN_REDIRECT=/dashboard
NUXT_PUBLIC_IDENTITY_AUTH_LOGOUT_REDIRECT=/auth/login
NUXT_PUBLIC_IDENTITY_AUTH_REGISTER_REDIRECT=/auth/verify-email
```

Redirects must be local application paths. The package rejects external redirect targets.

## Custom pages

Custom pages should call `useIdentityAuth()` and use the local BFF operations (`login`, `register`, `forgotPassword`, `resetPassword`, `resendVerification`, `verifyEmail`, and `logout`). They must not call the identity service directly. The Identity Console's `/admin/sign-in` page is the reference custom-page integration.

## Migration and compatibility

During migration, the Identity Console may retain `/api/identity/auth/*` compatibility handlers. New consumers and updated pages use `/api/auth/*`. Application-specific role mapping and business-account cleanup remain thin adapters in the consumer repository.

This package gives applications shared authentication behavior while retaining independent BFF sessions. Cross-application single sign-on requires a later authorization-code and PKCE/OIDC flow hosted by the identity service; sharing cookies or client secrets is not an SSO design.

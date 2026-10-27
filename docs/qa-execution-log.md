# QA execution log

## 2026-10-27T03:44:13Z — Full local release guard for hosted auth and demo cleanup

- Target/root: public `identity`; actor: current Codex session; release branch `codex/full-local-staging-20261026`.
- Action/method: corrected temporary logout feature fixtures to use the supported sandbox-session route; documented the contract in CI failure prevention. Used PHP 8.5's matching SQLite modules extracted under `/tmp` for local validation; no host package was installed.
- Verification: full `./scripts/ci pre-push` passed on Node 24: frozen install, ESLint, Nuxt typecheck and production build, Composer validation, Pint, all 118 PHP tests (977 assertions), DCO and tracked-secret scans, Compose config validation, and production dependency/security audits. `git diff --check` passed. The build wrapper restored the same 16 running Compose services on Docker context `default`.
- Runtime/providers: local stack only; no credentials, accounts, or staging resources changed. No branch was pushed and nothing was deployed.
- Limitations: GitHub CLI authentication is currently invalid, so remote publication and staging promotion remain blocked pending a fresh login.

## 2026-10-17T21:59:32Z — Live hosted policy-consent flow

- **Target:** Local Job Tracker hosted login (`localhost:3100`), Identity API/MySQL, and the Identity/Talred integration source; actor: current Codex session.
- **Action/method:** Traced the demo 422 to `terms_required=true` with a null hosted policy revision. Applied the additive `privacy_url` consent migration, then set the Job Tracker revision to `2026-10-12`, matching the last-updated date on both configured legal pages. Extracted the shared login implementation from the Nuxt page file and pointed the Job Tracker wrapper at the component, preventing the imported page metadata macro from running as component code.
- **Verification:** Migration status reports the consent migration as `Ran`; live application config reports terms acceptance enabled, revision `2026-10-12`, and both policy URLs present. The current browser tab now opens the consent dialog with both links; its checkbox is unchecked and “Accept and create demo account” is disabled. No new console warning appeared after the login component refactor. Targeted ESLint passed in Identity and Talred; `git diff --check` passed. The focused backend suite from the preceding feature pass remains green: 8 tests, 59 assertions.
- **Runtime/data:** Local Identity API, Console, Job Tracker API, MySQL, Redis, and the original Compose services remain running. No account was created and no legal acceptance was submitted.
- **Limitations:** The final live creation step requires accepting the Terms of Service and Privacy Policy. It was left for the user to review and authorize; the current tab is waiting at that consent dialog.

## 2026-10-17T21:48:19Z — Hosted policy acceptance for new accounts and demos

- **Target:** Public `talredservices/identity` source repository; actor: current Codex session.
- **Action/method:** Added the hosted application's `Require terms acceptance` configuration with Terms of Service and optional Privacy Policy URLs and a policy revision. Hosted password registration, Google-created accounts, and temporary demo-account creation now require explicit acceptance when enabled; accepted consent is persisted with the application, current revision, policy URLs, and acceptance timestamp. The hosted auth pages show a shared policy-consent dialog before these account-creation paths.
- **Verification:** Focused Identity API regression suite passed: 8 tests, 59 assertions. Targeted ESLint passed for the hosted-auth UI/BFF and application configuration paths. Laravel Pint and `git diff --check` passed. Nuxt typecheck ran through `local-dev-ops/scripts/with-stack-paused`; the wrapper restored and verified the original Compose container set. Typecheck ended with six unresolved TS2307 errors for missing `vue`/`@nuxt/ui` dependencies in Starter's external `integrations/identity-hosted-auth/page-sets` files; no Identity auth-page errors remained. Node 25.9.0 also emitted Nuxt's unsupported-version warning.
- **Runtime/data:** No hosted-application setting was changed and no live demo session was used for this feature check. The new consent migration was not applied to the shared local database.
- **Limitations:** Full Nuxt typecheck remains blocked by the external Starter integration dependency resolution errors; live browser behavior was not exercised.

## 2026-10-17T21:14:33Z — Live demo-only hosted authentication smoke test

- **Target:** Local Identity Console/API and the Job Tracker hosted application in the Talred workspace; actor: current Codex session.
- **Action/method:** Temporarily enabled `Demo sign-in only` on the Job Tracker hosted application, exercised its hosted sign-in page and BFF endpoints, then restored the original setting (`false`).
- **Verification:** The email/password fields and Sign in button were disabled in the browser while the demo action remained available. Direct BFF requests for primary login, registration, and password recovery each returned HTTP 403 with dummy payloads. Creating an instant demo account succeeded and returned to Job Tracker as a temporary sandbox user; the application displayed its expiry notice. The original hosted-application setting was saved back to `false`.
- **Runtime/data:** A temporary sandbox user/session remains open in the browser for inspection and is scheduled to expire in about 60 minutes; logging out deletes it immediately. No real user credentials were submitted.
- **Limitations:** This was a live smoke test, not an automated suite run. Google sign-in and password reset were not manually exercised; focused automated coverage for those paths is recorded above.

## 2026-10-17T20:59:53Z — Owner recovery environment configuration fix

- **Target:** Public `talredservices/identity` source repository.
- **Actor:** Codex local session.
- **Action/method:** Mapped `IDENTITY_RECOVERY_KEY` in `config/zolta.php`; changed `identity:recover-owner` to read `zolta.identity.recovery_key`; changed the recovery test to set config explicitly; documented the optional blank value in `.env.example`. Root cause: the test's `putenv()` could not override the non-empty key already loaded from this workspace's `.env` through Laravel's immutable environment repository. Runtime `env()` access also violated Laravel's config-cache contract. No secret value was emitted or recorded.
- **Verification:** Recovery test passed alone (1 test, 10 assertions). Full backend suite passed (111 tests, 889 assertions). Laravel Pint and `git diff --check` passed. This result supersedes the earlier full-suite failure recorded at 20:56:42Z.
- **Limitations:** No local runtime or production setting was changed.

## 2026-10-17T20:56:42Z — Full Identity backend suite

- **Target:** Public `talredservices/identity` source repository.
- **Actor:** Codex local session.
- **Action/method:** Ran `php artisan test --compact` from `apps/identity-server` after the focused demo-only regression tests passed.
- **Verification:** 110 tests passed; 1 test failed (884 assertions total). `test_recovery_rotates_owner_credentials_without_erasing_identity_data` expected command exit code 0 but received 1. Running that test alone reproduced the failure, so it is independent of the new demo-only tests. The focused demo-only tests remain green: 3 tests, 59 assertions.
- **Limitations:** The unrelated owner-recovery test failure was not modified or diagnosed further. Tests use the configured in-memory SQLite environment; no local runtime setting was changed.

## 2026-10-17T20:54:55Z — Demo-only hosted authentication tests

- **Target:** Public `talredservices/identity` source repository.
- **Actor:** Codex local session.
- **Action/method:** Added feature regression coverage in `apps/identity-server/tests/Feature/IdentityAccessServiceTest.php` for 403 responses on primary login, registration, Google sign-in, forgot-password, and reset-password; confirmed hosted sandbox-session creation remains available; checked the default remains false; exercised admin API persistence of `authentication.demo_only` with a linked sandbox client and verified that the active required client cannot be disabled.
- **Verification:** Focused PHPUnit run passed: 3 tests, 59 assertions, using the configured in-memory SQLite test database. `vendor/bin/pint --dirty --format agent` passed. `git diff --check` passed.
- **Limitations:** The full backend suite, Nuxt typecheck, and live dashboard/browser behavior were not run. No local runtime setting was changed.

## 2026-10-17T20:50:32Z — Demo-only hosted authentication

- **Target:** Public `talredservices/identity` source repository.
- **Actor:** Codex local session.
- **Action/method:** Added the per-hosted-application `authentication.demo_only` option to Identity dashboard create/edit flows and the hosted-application API contract. Existing authentication JSON stores the setting; no database migration was needed. Hosted login, registration, forgot-password, and reset forms disable normal controls when enabled. Both the Nuxt hosted-auth BFF and Identity hosted-auth API reject primary login, registration, Google sign-in, password recovery, and reset. The configured sandbox path remains available. Prevented disabling a sandbox client required by an active demo-only application.
- **Verification:** Zolta domain transformation validation returned `valid: true`; targeted ESLint passed; Laravel Pint passed; PHP syntax checks passed for all four changed PHP files; `git diff --check` passed.
- **Limitations:** Tests, Nuxt typecheck, and live dashboard/browser behavior were not run. Laravel Boost's required `search-docs` MCP tool was not exposed in this session. No local or deployed application setting was enabled.

## 2026-10-15T22:52:34Z — Clean-clone Docker first boot

- **Target:** Public `talredservices/identity` repository; tested from an isolated temporary clone.
- **Actor:** Codex local session
- **Source:** Fresh local Git clone of committed revision `46c98b064b4c0f8a4ca3093886cdcee8a6e98e36` on `codex/import-current-identity-source`. Fetching the public GitHub remote was unavailable because DNS could not resolve `github.com`; this does not verify the remote's current default branch.
- **Action/method:** Generated private first-run settings, used separate loopback ports 18100/13100, then ran `scripts/docker-local up` through the workspace's stack-pause wrapper. The script built all Identity images, migrated the clean database, bootstrapped the initial owner/client, and started the stack. Ran `scripts/docker-local down` afterward; it removed the temporary containers/network but preserved the clone's newly created volumes.
- **Verification:** All seven fresh-clone services started; MySQL and Redis were healthy. API `/up` returned HTTP 200. Console sign-in SSR returned HTTP 200 with email/password fields; the Nuxt bundle returned HTTP 200. Unauthenticated `/admin` redirected to sign-in. Migration status showed no pending migrations. After test cleanup, the original stack's API and console still returned HTTP 200.
- **Limitations:** No interactive browser sign-in or hydration run; browser control had rejected the local URL. This was a fresh clone of the local committed revision, not a clone of the remote GitHub default branch.

## 2026-10-15T22:47:08Z — Docker-backed Identity runtime check

- **Target:** Public `talredservices/identity` working tree.
- **Actor:** Codex local session
- **Action/method:** Restarted the existing stopped Identity Compose containers in dependency order. Preserved all existing volumes. The generated `.env.docker.local` was absent, so did not invoke the setup script, which would generate a new project identity and separate volumes.
- **Services:** MySQL, Redis, Identity API, queue worker, scheduler, Nuxt console, and Nginx are running; MySQL and Redis report healthy.
- **Verification:** API `/up` returned HTTP 200. Console `/admin/sign-in` returned HTTP 200 with a server-rendered sign-in form; its Nuxt JS bundle returned HTTP 200. Unauthenticated `/admin` redirected to `/admin/sign-in`. Laravel migration status completed with no pending migrations.
- **Limitations:** Browser control rejected opening the local sign-in URL, so an interactive sign-in and client-side hydration check were not completed. No containers were rebuilt; this checks the existing included Compose runtime, not a clean image build.

## 2026-10-15T22:35:27Z — Local fresh-install smoke review

- **Target:** Public `talredservices/identity` working tree.
- **Actor:** Codex local session
- **Method:** Browser-based smoke review of the running Nuxt console and Laravel API, plus targeted frontend checks and the backend suite.
- **Services:** Identity Console at `http://localhost:3100`; Identity API at `http://127.0.0.1:8100`.
- **Changes verified:** Project creation uses Nuxt UI form validation and reports success or readable failure feedback. Directory search now initializes from the URL before debouncing for projects, clients, and applications.
- **Browser results:** Existing bootstrap owner signed in. Overview, projects, project detail, clients, applications, installation users, access catalog, and account pages loaded. Project creation validation surfaced required-field guidance; a successful test project creation and duplicate-slug response were confirmed. Cold direct searches for unmatched `q` values returned zero results on all three directories. Interactive project search returned one matching result and restored both records when cleared. The API `/up` page rendered `Application up` in the browser.
- **Checks:** Targeted ESLint passed. `with-stack-paused -- pnpm run lint:types` passed; the wrapper found no Compose services running and left the stack stopped. `pnpm run test:backend` passed: 109 tests, 869 assertions.
- **Local data:** A throwaway QA project remains in the local Identity database for inspection. `.env` and `.identity-owner.credentials` remain ignored and mode `0600`; no values were recorded.
- **Limitations:** Node `25.9.0` is outside Nuxt's supported version ranges; typecheck still passed. A Vue runtime overlay reading `flags` appeared during hot reload earlier in this QA session and disappeared after a cold reload. No overlay appeared on the cold routes checked, but browser console warnings were not directly available through the current UI control, so hydration is not claimed fully resolved. Shell localhost probes are isolated from the browser network; service health was verified in the browser instead.

## 2026-10-21T01:44:06Z — Hosted-auth release gate

- **Target:** Public `talredservices/identity` source and its configured Starter hosted-auth page sets.
- **Actor:** Interactive Codex session.
- **Action/method:** Fixed Nuxt app tsconfig resolution for consumer-owned page sets and refreshed the route compatibility snapshot after reviewing the named sandbox limiter change. Added the failure-prevention note.
- **Verification:** `./scripts/ci pre-push` passed: Node lint and typecheck, Nuxt production build, Composer validation/install/Pint, 115 Laravel tests with 956 assertions, tracked-secret scan, production JS audit, and Composer audit. The canonical stack wrapper restored and verified the original running service set.
- **Limitations:** This gate verifies source and build contracts; protected staging browser flows remain part of the deployment workflow.

## 2026-10-18T01:48:57Z — Sandbox throttle separation

- Target/root: public `identity`, `/home/redouane/projects/talredservices/identity`; actor: interactive Codex session.
- Action: added named sandbox limiter and applied it to generic/hosted sandbox-session routes after verified local 429 collisions with context traffic. Ceiling remains 20/IP/minute shared by both routes. Public Starter was not edited.
- Verification: focused regression set passed 3 tests and 106 assertions using isolated testing SQLite; provider/controller/test whitespace checks passed. Two affected attribute route manifests regenerated on local main Identity. Tasks instant-demo browser callback reached its protected dashboard and created a project after the fix.
- Services/providers: existing local Identity API; no external publishing, deployment or production operation. No release build required for this PHP routing change.

## 2026-10-24T20:05:18Z — Preserve consumer branding on hosted auth pages

- Target: public Identity hosted-auth presentation layer; actor: interactive Codex session.
- Action/method: kept the generic Identity header/mark on the default page set while allowing consumer-provided page-set headers to render without a second Identity mark.
- Verification: browser smoke review of the local SaaS hosted-auth page showed one consumer-branded header; `git diff --check` passed.
- Providers/runtime: local Identity Console only; no public image, Staging, or production deployment was performed.
- Limitation: final end-to-end authorization remained blocked by HTTP 429 responses from the local Starter BFF during repeated authorization-intent retries.


## 2026-10-26T05:42:13Z — Hosted auth document navigation regression

- Target/root: public `identity`; actor: interactive Codex session.
- Action: registered the Nuxt 4.6.0 document-scope patch in pnpm; kept the outgoing app alive until browser document unload, preserving head-managed styles and browser Back. The existing hosted-login pending branch renders on initial HTML while client metadata loads, with route-selected consumer branding. The entry resolver reuses its resolved application rather than fetching it twice.
- Verification: frozen dependency installation succeeded in both existing Identity console runtimes. Both load the patched implementation. Job Tracker and SaaS entry flows reached their correct hosted login pages; Job Tracker Login → Back passed in both themes and Chrome. Targeted auth-page/server ESLint and diff checks passed. No authentication credentials were entered.
- Runtime/providers: scoped default-daemon Nuxt restarts only; no provider changes or publication.
- Limitations: no production build or authenticated logout test. Startup still emitted the existing Vite i18n `#components` dependency-scan warning; the tested routes rendered and worked.

## 2026-10-26T21:10:29Z — Hosted auth first-frame branding

- Target/root: public `identity`; actor: interactive Codex session.
- Action: render the resolved hosted application brand in the initial HTML for hosted login/logout, and show the brand mark in the existing login pending/error card. Other auth pages retain the client-deferred metadata request.
- Verification: local GET responses for hosted SaaS login and logout returned 200; both first HTML responses included Talred Services.com, the `saas` page set, the purple accent, and their existing transition copy. The Job Tracker login click showed its branded loading card before the existing form appeared. Targeted ESLint and `git diff --check` passed. No credentials or logout intent were submitted.
- Runtime/providers: source is bind-mounted in the existing local Identity Console; no Docker operation, account action, or provider change was performed.
- Limitation: authenticated logout was not repeated; verification covered its initial server-rendered response only.

## 2026-10-26T21:17:37Z — Login loading-copy refinement

- Target/root: public `identity`; actor: interactive Codex session.
- Action: replaced the two repetitive loading lines with one status message, “Preparing your sign-in…”, announced politely to assistive technology.
- Verification: fresh local SaaS login navigation displayed the single message in the first-frame accessibility tree; the existing sign-in form then loaded. A read-only first-HTML check confirmed the new text and absence of both old strings. Targeted ESLint and `git diff --check` passed.
- Runtime/providers: existing local Identity Console only; no credentials, terms acceptance, or logout intent used. No deployment.

## 2026-10-26T21:42:35Z — Show hosted brand mark above sign-in form

- Target/root: public `identity`; actor: interactive Codex session.
- Action: let consumer page-set shells opt in to rendering the hosted brand mark in the login form header while preserving default-page behavior and leaving other hosted screens unchanged.
- Method: added the `identity-auth-show-login-brand-mark` injection contract to the shared form header and restricted it to `/auth/.../login` routes.
- Verification: local Tasks, Job Tracker, and Starter sign-in forms rendered the configured mark immediately above “Sign in.” Targeted ESLint passed; no build or tests ran.
- Runtime/providers: existing local Identity Console on port 3100; no runtime restart or provider changes.
- Limitations: none for the verified local login screens.

## 2026-10-26T21:59:05Z — Place sign-in attribution beneath the form

- Target/root: public `identity`; actor: interactive Codex session.
- Action: render Terms of Service, Privacy Policy, and Identity attribution beneath the login card, constrained to the form width; suppress the viewport-edge footer on login routes. Other auth screens keep their current footer placement.
- Method: render `IdentityAttribution` within `IdentityHostedLoginPage` and retain layout-level attribution only off login routes.
- Verification: fresh local Job Tracker and Tasks sign-in flows showed the form followed by legal links and credit below the card; Starter showed the credit below its form. Targeted ESLint and scoped `git diff --check` passed. No build or tests ran.
- Runtime/providers: existing local Identity Console on port 3100; no restart or provider changes.
- Limitation: the Starter login currently provides no Terms of Service or Privacy Policy URLs, so those links are absent there.

## 2026-10-26T22:34:04Z — Recover expired hosted authorization flow

- Target/root: public `identity`; actor: interactive Codex session.
- Action: when a hosted sign-in flow expires, send the browser to the consumer's localized auth entry route with the original app return path, instead of constructing a nonexistent `/en/jobs/auth/login` path on Identity.
- Method/artifacts: added `hosted-auth-restart.ts`; updated `identity-live-auth.ts`; added focused Node tests in `tests/hosted-auth-restart.test.mjs`.
- Verification: 5 focused tests passed; scoped ESLint passed; `git diff --check` passed. In the integrated browser, the consumer entry route started a fresh Job Tracker hosted sign-in and rendered the form. No credentials were submitted.
- Runtime/providers: existing bind-mounted local Identity Console; no Docker operation, restart, deployment, or provider change.
- Limitation: the exact 10-minute expiration was not waited out in the shared browser session; expired-flow URL construction is covered by focused tests, and the recovery route was verified live.

## 2026-10-26T23:37:14Z — Enforce demo logout cleanup and lifetime cap

- Target/root: public `identity`, `/home/redouane/projects/talredservices/identity/apps/identity-server`; actor: current Codex session.
- Action: capped temporary demo-account lifetime at 60 minutes even if configuration requests longer; temporary logout now revokes all user tokens, starts signed deletion requests, and removes Identity-owned records directly when no consumer is subscribed. Identity waits for configured consumer acknowledgements before finalizing its account. Removed email addresses from stored deletion-request payloads.
- Verification: added focused tests for logout token revocation, direct cleanup without consumers, finalization after a consumer acknowledgement, and the 60-minute cap. Pint, PHP syntax checks, and scoped `git diff --check` passed. The focused Laravel suite was attempted but all six tests stopped before assertions because host PHP has no `pdo_sqlite` driver.
- Runtime/providers: no runtime, provider, or deployment changes. Default-daemon inspection was denied access to the Docker socket; no Compose state changed.
- Limitations: live provider configuration, consumer webhook subscriptions, queue health, and scheduler health were not verified. Delivery exhaustion still needs operator action, as reflected in the Talred privacy copy.

## 2026-10-27T00:09:16Z — Center hosted logout transition content

- Target/root: public `identity`; actor: interactive Codex session.
- Action: group the “Signing out” card, legal links, and Identity credit in one centered logout layout. Suppress the layout-level edge footer on logout routes and keep the grouped attribution centered on narrow screens.
- Method/artifacts: updated `identity-auth.vue`, `IdentityAttribution.vue`, and `auth/logout.vue`.
- Verification: local logout preview with an invalid one-time intent showed the status, legal links, and credit together in the page accessibility tree; no valid user logout was triggered. Scoped ESLint, `node --test tests/hosted-auth-restart.test.mjs`, and scoped `git diff --check` passed.
- Runtime/providers: used the existing local Identity Console on port 3100; no service restart, Docker operation, provider change, or deployment.
- Limitation: did not complete a valid session logout or verify the pixel layout through a full browser screenshot.

## 2026-10-27T00:20:48Z — Theme hosted policy consent modal per application

- Target/root: public `identity`; actor: interactive Codex session.
- Action: make the hosted policy consent dialog inherit the active application's primary palette, light/dark surface, text, and border tokens instead of falling back to Identity's global blue theme.
- Method/artifacts: added `hosted-modal-theme.ts`, applied its variables to the teleported modal content, and added focused tests in `tests/hosted-modal-theme.test.mjs`.
- Verification: 3 focused theme tests passed; scoped ESLint and `git diff --check` passed. The local Starter sign-in preview showed teal links and primary action with the Starter dark surfaces. Terms acceptance and demo-account creation were not submitted.
- Runtime/providers: existing local Identity Console on port 3100; no runtime restart, Docker operation, provider change, or deployment.
- Limitations: live visual preview covered Starter dark mode; SaaS/Tasks palettes and light mode are covered by the focused style-mapping tests.

## 2026-10-27T01:34:14Z — Verify hosted sign-in branding and consent themes

- Target/root: public `identity`; actor: interactive Codex session.
- Action: rechecked local hosted sign-in for Tasks, Starter, SaaS, and Job Tracker in light and dark modes. Each page showed the configured product badge and brand mark above the sign-in heading, with policy links and Identity attribution below the form. The initial pending frame used the branded sign-in shell and “Preparing your sign-in…” status.
- Verification: opened the Tasks consent modal in both themes and confirmed its light/dark surfaces and indigo accent follow the hosted app. Dismissed the modal without accepting terms or creating an account. Forms loaded successfully after their context request.
- Runtime/providers: existing local Identity Console on port 3100 with consumer integration mounts; no service restart, Docker operation, provider change, or deployment.
- Limitations: no credentials were submitted and no valid logout was exercised. No tests or build were run in this verification pass.

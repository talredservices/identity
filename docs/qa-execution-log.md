# QA execution log

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

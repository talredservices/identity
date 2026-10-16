# QA execution log

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

# CI failure prevention

## Owner recovery key and Laravel configuration

The owner-recovery feature test failed in a workspace with a configured
`IDENTITY_RECOVERY_KEY`: the test's `putenv()` value did not replace the value
already loaded by Laravel's immutable environment repository. The command also
read `env()` at runtime, which is not reliable after Laravel configuration is
cached.

Keep `IDENTITY_RECOVERY_KEY` mapped in `config/zolta.php`, read it in the
command with `config('zolta.identity.recovery_key')`, and set the config value
directly in tests. Keep the example environment value blank and never print or
record the configured secret.

## Production audit and Nuxt peer dependencies

The workspace-wide `pnpm audit --prod` treated the Nuxt peer dependency in
`layers/auth` as a production dependency owned by Identity. This reported
`node-forge` and `braces` advisories from Nuxt's build toolchain, although Nuxt
is consumer-provided by the auth layer and the Identity Console declares Nuxt
as a development dependency. The Docker runtime copies only the generated
`.output` directory.

The production gate now runs `pnpm run security:audit:js`, which audits the
production importers owned by the Identity app and auth layer in an isolated
temporary workspace. It removes only pnpm's auto-installed copy of the auth
layer's Nuxt peer from that audit graph; the published peer declaration and
normal workspace peer installation remain unchanged. Nuxt consumers must
audit the Nuxt version they choose in their own application.

The advisories remain recorded upstream as unpatched at the time this change
was verified: [node-forge GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv)
and [braces GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm).
Do not hide them with audit ignores or dependency overrides to unavailable
versions. Revisit the dependency graph and audit scope when upstream publishes
patched releases or Identity changes which package owns Nuxt.

## Sandbox creation must not share unnamed throttle buckets

Local sandbox browser verification returned 429 after ordinary hosted context
traffic, because unnamed `throttle:20,1` shared Laravel's domain/IP request key
with other numeric throttles. Both sandbox creation routes now use the named
`identity-sandbox-session` limiter. Its ceiling remains 20 requests per IP per
minute, shared by generic and hosted entry, while context and configuration
requests do not consume it. The focused feature regression exhausts context
traffic, verifies 20 sandbox attempts, then checks 429 on both entry routes.

## External hosted-auth page-set type resolution

The local Identity host can load consumer-owned page sets from a sibling
repository through `IDENTITY_HOSTED_AUTH_INTEGRATIONS`. Those files compile as
part of the Identity app, so their `vue` and `@nuxt/ui` imports must resolve to
the host's installed dependencies. Put these mappings in Nuxt's
`typescript.appTsConfig.compilerOptions.paths`; edits to the root `tsconfig`
do not reach Nuxt 4's generated app project. Keep this configuration host-owned
and avoid adding consumer code or dependencies to the public Identity source.

## PECL Redis 6.3.0 release metadata (2026-10-21)

The identity-server image build failed because `pecl install redis` resolved
Redis 6.3.0, whose PECL release metadata is missing the REST XML required by
the installer. Pin the production and development images to `redis-6.2.0`
until PECL publishes complete metadata for 6.3.0 or a later release. The
upstream issue is tracked at
[phpredis/phpredis#2870](https://github.com/phpredis/phpredis/issues/2870);
the pinned release remains published on [PECL](https://pecl.php.net/package/redis/6.2.0).

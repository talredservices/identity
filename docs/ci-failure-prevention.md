# CI failure prevention

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

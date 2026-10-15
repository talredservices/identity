import { spawnSync } from 'node:child_process'
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(fileURLToPath(new URL('..', import.meta.url)))
const packageManifest = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'))
const authManifest = JSON.parse(readFileSync(join(root, 'layers/auth/package.json'), 'utf8'))

if (!packageManifest.devDependencies?.nuxt || packageManifest.dependencies?.nuxt) {
  throw new Error('Nuxt must remain a build-time dependency of the Identity Console.')
}

if (!authManifest.peerDependencies?.nuxt || authManifest.dependencies?.nuxt) {
  throw new Error('The auth layer must declare Nuxt as a consumer-provided peer dependency.')
}

const lockPath = join(root, 'pnpm-lock.yaml')
const lock = readFileSync(lockPath, 'utf8')
const importersStart = lock.indexOf('importers:\n')
const packagesStart = lock.indexOf('\npackages:\n', importersStart)

if (importersStart < 0 || packagesStart < 0) {
  throw new Error('Could not locate pnpm lockfile importers and package sections.')
}

const importerHeader = 'importers:\n'
const importerText = lock.slice(importersStart + importerHeader.length, packagesStart)
const importerBlocks = new Map()
const lines = importerText.split(/(?<=\n)/)
let currentName

for (const line of lines) {
  const header = line.match(/^  ([^\s].*):\s*\n?$/)
  if (header) {
    currentName = header[1]
    importerBlocks.set(currentName, '')
  }
  if (currentName !== undefined) {
    importerBlocks.set(currentName, importerBlocks.get(currentName) + line)
  }
}

const rootImporter = importerBlocks.get('.')
let authImporter = importerBlocks.get('layers/auth')

if (!rootImporter || !authImporter) {
  throw new Error('Expected root and layers/auth importers in pnpm-lock.yaml.')
}

// pnpm may materialize a workspace package's peer dependency in its lock importer.
// The auth layer does not own Nuxt; downstream Nuxt apps must audit their chosen Nuxt version.
const peerEntry = /^      nuxt:\n(?:        [^\n]*\n)+/m
authImporter = authImporter.replace(peerEntry, '')

if (/^      nuxt:\n/m.test(authImporter)) {
  throw new Error('Could not exclude the auto-installed Nuxt peer from the auth-layer audit importer.')
}

const auditLock = [
  lock.slice(0, importersStart) + importerHeader,
  rootImporter.trimEnd(),
  authImporter.trimEnd(),
  lock.slice(packagesStart + 1),
].join('\n\n')

const temp = mkdtempSync(join(tmpdir(), 'talred-identity-prod-audit-'))

try {
  copyFileSync(join(root, 'package.json'), join(temp, 'package.json'))
  mkdirSync(join(temp, 'layers/auth'), { recursive: true })
  copyFileSync(join(root, 'layers/auth/package.json'), join(temp, 'layers/auth/package.json'))

  // Keep this audit workspace limited to the two packages that own production dependencies.
  // Disable peer auto-install only in the temporary audit workspace so Nuxt remains consumer-owned.
  writeFileSync(join(temp, 'pnpm-workspace.yaml'), 'packages:\n  - layers/auth\nautoInstallPeers: false\n')
  const lockForAudit = auditLock.replace('  autoInstallPeers: true\n', '  autoInstallPeers: false\n')
  writeFileSync(join(temp, 'pnpm-lock.yaml'), lockForAudit)

  console.log('Auditing production dependencies owned by the Identity app and auth layer.')
  console.log('Nuxt is audited by each consuming Nuxt app as a peer dependency.')

  const result = spawnSync('pnpm', ['audit', '--prod'], {
    cwd: temp,
    env: { ...process.env, CI: 'true' },
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  })

  if (result.stdout) process.stdout.write(result.stdout)
  if (result.stderr) process.stderr.write(result.stderr)

  if (result.error) throw result.error
  process.exitCode = result.status ?? 1
} finally {
  rmSync(temp, { force: true, recursive: true })
}

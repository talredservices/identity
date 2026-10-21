function normalizeHost(value: unknown): string {
  return typeof value === 'string' ? value.trim().toLowerCase() : ''
}

function isAdminPage(path: string): boolean {
  const segments = path.split('/').filter(Boolean)

  return segments[0] === 'admin'
    || (['en', 'fr'].includes(segments[0] ?? '') && segments[1] === 'admin')
}

function isAdminBffRequest(path: string): boolean {
  return path === '/api/auth/session'
    || path.startsWith('/api/identity/')
}

function isPublicIdentityPage(path: string): boolean {
  const segments = path.split('/').filter(Boolean)
  const firstPageSegment = ['en', 'fr'].includes(segments[0] ?? '')
    ? segments[1]
    : segments[0]

  return firstPageSegment === 'auth' || firstPageSegment === 'account'
}

export default defineEventHandler((event) => {
  const config = useRuntimeConfig(event)
  const request = getRequestURL(event)
  const host = request.hostname.toLowerCase()
  const publicHost = normalizeHost(config.admin.publicHost)
  const adminHost = normalizeHost(config.admin.host)

  // Host separation is opt-in so local development and single-host installs
  // retain their existing behavior until two distinct production hosts are configured.
  if (!publicHost || !adminHost || publicHost === adminHost) return

  if (host === publicHost && (isAdminPage(request.pathname) || isAdminBffRequest(request.pathname))) {
    throw createError({ statusCode: 404, statusMessage: 'Not Found' })
  }

  if (host === adminHost && isPublicIdentityPage(request.pathname)) {
    throw createError({ statusCode: 404, statusMessage: 'Not Found' })
  }
})

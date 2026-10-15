function normalizeHost(value: unknown): string {
  return typeof value === 'string' ? value.trim().toLowerCase() : ''
}

export default defineEventHandler((event) => {
  const request = getRequestURL(event)

  if (request.pathname !== '/') return

  const config = useRuntimeConfig(event)
  const host = request.hostname.toLowerCase()
  const publicHost = normalizeHost(config.admin.publicHost)
  const adminHost = normalizeHost(config.admin.host)

  if (publicHost && adminHost && host === publicHost) {
    return sendRedirect(event, '/auth/login', 302)
  }

  return sendRedirect(event, '/admin/sign-in', 302)
})

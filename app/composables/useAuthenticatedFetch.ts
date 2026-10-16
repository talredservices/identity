export function useAuthenticatedFetch() {
  const { isUnauthorizedError, clearSessionAndRedirect } = useUnauthorizedSessionHandler()
  const requestFetch = import.meta.server ? useRequestFetch() : $fetch

  return async function authenticatedFetch<T>(
    request: string,
    options?: Omit<NonNullable<Parameters<ReturnType<typeof useRequestFetch>>[1]>, 'baseURL'> & { baseURL?: '' }
  ): Promise<T> {
    try {
      return await requestFetch(request, options) as T
    } catch (error) {
      if (isUnauthorizedError(error)) {
        await clearSessionAndRedirect()
      }

      throw error
    }
  }
}

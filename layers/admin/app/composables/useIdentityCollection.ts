export function useIdentityCollection<T>(
  source: MaybeRefOrGetter<T[] | null | undefined>,
  searchableText: (item: T) => string,
  pageSize = 10,
  initialSearch = ''
) {
  const search = ref(initialSearch)
  const debouncedSearch = refDebounced(search, 250)
  const page = ref(1)

  const items = computed(() => toValue(source) ?? [])
  const filteredItems = computed(() => {
    const query = debouncedSearch.value.trim().toLocaleLowerCase()
    if (!query) return items.value

    return items.value.filter(item => searchableText(item).toLocaleLowerCase().includes(query))
  })
  const paginatedItems = computed(() => {
    const offset = (page.value - 1) * pageSize
    return filteredItems.value.slice(offset, offset + pageSize)
  })

  watch([debouncedSearch, () => items.value.length], () => {
    page.value = 1
  })

  return {
    search,
    page,
    pageSize,
    items,
    filteredItems,
    paginatedItems,
    total: computed(() => filteredItems.value.length),
    clearSearch: () => { search.value = '' }
  }
}

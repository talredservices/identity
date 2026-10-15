export type IdentityHostedPageSet = string

export function identityHostedPageSet(
  configuredPageSet: unknown,
  enabledPageSets: unknown
): IdentityHostedPageSet {
  if (typeof configuredPageSet !== 'string' || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(configuredPageSet)) {
    return 'default'
  }

  let enabled: string[]
  if (Array.isArray(enabledPageSets)) {
    enabled = enabledPageSets.filter((item): item is string => typeof item === 'string')
  } else if (typeof enabledPageSets === 'string') {
    enabled = enabledPageSets.split(',').map(pageSet => pageSet.trim()).filter(Boolean)
  } else {
    enabled = ['default']
  }

  return enabled.includes(configuredPageSet) ? configuredPageSet : 'default'
}

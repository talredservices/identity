export type HostedModalAppearance = {
  accentColor?: string | null
  designTokens?: Record<string, string>
}

export type HostedModalColorMode = 'light' | 'dark'

const fallbackTokens = {
  light: {
    background: '#faf9f7',
    backgroundMuted: '#eef3ef',
    card: '#ffffff',
    text: '#172033',
    muted: '#59657b',
    border: '#e1e5ee'
  },
  dark: {
    background: '#0f172a',
    backgroundMuted: '#0f172a',
    card: '#111827',
    text: '#f8fafc',
    muted: '#cbd5e1',
    border: '#334155'
  }
} satisfies Record<HostedModalColorMode, Record<string, string>>

export function hostedModalThemeStyle(
  appearance: HostedModalAppearance | null | undefined,
  colorMode: HostedModalColorMode
): Record<string, string> {
  const tokens = appearance?.designTokens ?? {}
  const accent = tokens.accent ?? appearance?.accentColor

  if (!Object.keys(tokens).length && !accent) return {}

  const styles: Record<string, string> = {}

  for (const [key, value] of Object.entries(tokens)) {
    styles[`--identity-hosted-${key.replaceAll('_', '-')}`] = value

    if (key.startsWith('primary_')) {
      styles[`--ui-color-primary-${key.slice('primary_'.length)}`] = value
    }
  }

  for (const shade of ['500', '600', '700']) {
    const value = tokens[`primary_${shade}`] ?? accent
    if (value) styles[`--ui-color-primary-${shade}`] = value
  }

  const primary = tokens.primary_600 ?? accent
  if (primary) {
    styles['--ui-primary'] = primary
    styles['--identity-auth-accent'] = accent ?? primary
  }

  const defaults = fallbackTokens[colorMode]
  const themed = (token: string, fallback: string) => tokens[`${colorMode}_${token}`] ?? fallback
  const background = themed('background', defaults.background)
  const backgroundMuted = themed('background_muted', defaults.backgroundMuted)
  const text = themed('text', defaults.text)
  const border = themed('border', defaults.border)

  styles['--ui-bg'] = background
  styles['--ui-bg-muted'] = backgroundMuted
  styles['--ui-bg-elevated'] = themed('card', defaults.card)
  styles['--ui-bg-accented'] = backgroundMuted
  styles['--ui-text'] = text
  styles['--ui-text-muted'] = themed('muted', defaults.muted)
  styles['--ui-text-highlighted'] = text
  styles['--ui-border'] = border
  styles['--ui-border-muted'] = border
  styles.colorScheme = colorMode

  return styles
}

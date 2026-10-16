<script setup lang="ts">
type HostedAppearance = {
  welcomeText: string | null
  accentColor: string | null
  backgroundPreset: 'identity' | 'slate' | 'indigo' | 'emerald' | 'sunset'
  logoUrl: string | null
  designTokens: Record<string, string>
}

type HostedBrand = {
  key: string
  name: string
  projectName?: string
  returnUrl: string
  presentation: HostedAppearance & { pageSet: string, productName: string }
  authentication: {
    termsUrl: string | null
    privacyUrl: string | null
  }
}

const route = useRoute()
const applicationKey = computed(() =>
  typeof route.query.application === 'string' ? route.query.application : ''
)
const clientId = computed(() =>
  typeof route.query.client_id === 'string' ? route.query.client_id : ''
)
const { data: experience } = await useAsyncData<HostedBrand | null>(
  'identity-auth-brand',
  async () => {
    if (!applicationKey.value && !clientId.value) return null
    const response = await $fetch<{ application: HostedBrand }>(
      '/api/hosted-auth/application',
      {
        query: applicationKey.value
          ? { application: applicationKey.value }
          : { clientId: clientId.value }
      }
    )
    return response.application
  },
  { watch: [applicationKey, clientId] }
)
const brand = computed(() => experience.value)
const config = useRuntimeConfig()
const productName = computed(
  () => brand.value?.presentation.productName ?? brand.value?.name ?? brand.value?.projectName ?? config.public.identityAuth.productName
)
const pageSet = computed(() => identityAuthPageSet(brand.value?.presentation.pageSet))
const backgroundPreset = computed(
  () => brand.value?.presentation.backgroundPreset ?? 'identity'
)
const brandStyle = computed(() => {
  const tokens = brand.value?.presentation.designTokens ?? {}
  const styles: Record<string, string> = {}

  for (const [key, value] of Object.entries(tokens)) {
    styles[`--identity-hosted-${key.replaceAll('_', '-')}`] = value
  }

  if (brand.value?.presentation.accentColor && !tokens.accent) {
    styles['--identity-hosted-accent'] = brand.value.presentation.accentColor
  }

  for (const [key, value] of Object.entries(tokens)) {
    if (key.startsWith('primary_')) styles[`--ui-color-primary-${key.slice(8)}`] = value
  }

  const accent = tokens.accent ?? brand.value?.presentation.accentColor ?? '#3157d5'
  styles['--ui-primary'] = tokens.primary_600 ?? accent

  // The auth logo uses the explicit Nuxt UI primary shade variables rather
  // than --ui-primary. Keep those variables in sync when a hosted app only
  // provides an accent (or a partial primary palette), otherwise Nuxt UI's
  // default blue palette leaks into the logo background.
  styles['--ui-color-primary-500'] = tokens.primary_500 ?? accent
  styles['--ui-color-primary-700'] = tokens.primary_700 ?? accent

  return styles
})

useHead(() => ({
  title: productName.value,
  meta: [
    { name: 'description', content: `Secure authentication for ${productName.value}.` }
  ]
}))

provide('identity-auth-brand', brand)
provide('identity-auth-page-set', pageSet)
</script>

<template>
  <main
    class="identity-auth-layout"
    :class="[
      `identity-auth-layout--${pageSet}`,
      `identity-auth-background--${backgroundPreset}`
    ]"
    :style="brandStyle"
  >
    <header
      class="identity-auth-header"
    >
      <div class="identity-auth-header-inner">
        <div
          class="identity-auth-header-brand"
        >
          <IdentityAuthBrandMark
            :logo-url="brand?.presentation.logoUrl"
            :accent-color="brand?.presentation.accentColor"
            size="header"
          />
          <span class="identity-auth-header-name">{{ productName }}</span>
        </div>
        <div class="identity-auth-header-controls">
          <IdentityConsoleControls compact />
        </div>
      </div>
    </header>

    <div class="identity-auth-content">
      <slot />
      <IdentityAttribution
        :terms-url="brand?.authentication.termsUrl"
        :privacy-url="brand?.authentication.privacyUrl"
      />
    </div>
  </main>
</template>

<style>
:root {
  --identity-auth-bg: #faf9f7;
  --identity-auth-card: #fff;
  --identity-auth-text: #172033;
  --identity-auth-muted: #59657b;
  --identity-auth-border: #e1e5ee;
  --identity-auth-input-border: #cdd3df;
  --identity-auth-status: #f1f4fb;
  --identity-auth-accent: #3157d5;
  color-scheme: light;
  font-family: var(--identity-auth-font, Inter),
    ui-sans-serif,
    system-ui,
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    sans-serif;
}

.dark {
  --identity-auth-bg: #0f172a;
  --identity-auth-card: #111827;
  --identity-auth-text: #f8fafc;
  --identity-auth-muted: #cbd5e1;
  --identity-auth-border: #334155;
  --identity-auth-input-border: #475569;
  --identity-auth-status: #1e293b;
  color-scheme: dark;
}

body {
  margin: 0;
  color: var(--identity-auth-text);
}

.identity-auth-layout {
  --identity-auth-font: var(--identity-hosted-font-family, Inter);
  --identity-auth-bg: var(--identity-hosted-light-background, #faf9f7);
  --identity-auth-bg-muted: var(--identity-hosted-light-background-muted, #eef3ef);
  --identity-auth-card: var(--identity-hosted-light-card, #fff);
  --identity-auth-text: var(--identity-hosted-light-text, #172033);
  --identity-auth-muted: var(--identity-hosted-light-muted, #59657b);
  --identity-auth-border: var(--identity-hosted-light-border, #e1e5ee);
  --identity-auth-input-border: var(--identity-hosted-light-input-border, #cdd3df);
  --identity-auth-status: var(--identity-hosted-light-status, #f1f4fb);
  --identity-auth-accent: var(--identity-hosted-accent, #3157d5);
  --ui-bg: var(--identity-auth-bg);
  --ui-bg-muted: var(--identity-auth-bg-muted);
  --ui-bg-elevated: var(--identity-auth-card);
  --ui-bg-accented: var(--identity-auth-bg-muted);
  --ui-text: var(--identity-auth-text);
  --ui-text-muted: var(--identity-auth-muted);
  --ui-text-highlighted: var(--identity-auth-text);
  --ui-border: var(--identity-auth-border);
  --ui-border-muted: var(--identity-auth-border);
  box-sizing: border-box;
  color: var(--identity-auth-text);
  display: flex;
  flex-direction: column;
  font-family: var(--identity-auth-font), ui-sans-serif, system-ui, sans-serif;
  min-height: 100vh;
  position: relative;
}

.dark .identity-auth-layout {
  --identity-auth-bg: var(--identity-hosted-dark-background, #0f172a);
  --identity-auth-bg-muted: var(--identity-hosted-dark-background-muted, #0f172a);
  --identity-auth-card: var(--identity-hosted-dark-card, #111827);
  --identity-auth-text: var(--identity-hosted-dark-text, #f8fafc);
  --identity-auth-muted: var(--identity-hosted-dark-muted, #cbd5e1);
  --identity-auth-border: var(--identity-hosted-dark-border, #334155);
  --identity-auth-input-border: var(--identity-hosted-dark-input-border, #475569);
  --identity-auth-status: var(--identity-hosted-dark-status, #1e293b);
}

.identity-auth-header {
  align-items: center;
  display: flex;
  justify-content: center;
  min-height: 3.5rem;
  padding-inline: clamp(1rem, 4vw, 2.5rem);
  background: color-mix(
    in srgb,
    var(--identity-auth-card) 88%,
    transparent
  );
  border-bottom-color: var(--identity-auth-border);
  flex: none;
  width: 100%;
}

.identity-auth-header-inner {
  align-items: center;
  display: flex;
  gap: 1rem;
  justify-content: space-between;
  margin-inline: auto;
  max-width: 80rem;
  width: 100%;
}

.identity-auth-header-brand {
  align-items: center;
  display: flex;
  gap: 0.75rem;
  max-width: min(70vw, 32rem);
  min-width: 0;
}

.identity-auth-header-name {
  color: var(--identity-auth-text);
  font-size: 1rem;
  font-weight: 650;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.identity-auth-header-controls {
  align-items: center;
  display: flex;
  flex: none;
  justify-content: flex-end;
}

.identity-auth-page,
.identity-auth-form-shell,
.identity-auth-form-stack {
  box-sizing: border-box;
  width: min(100%, 26rem);
}

.identity-auth-form-stack {
  display: grid;
  gap: 1rem;
}

.identity-auth-content {
  align-items: center;
  box-sizing: border-box;
  display: flex;
  flex: 1;
  flex-direction: column;
  justify-content: center;
  margin-inline: auto;
  padding: 2rem 1rem;
  width: min(100%, 28rem);
}

.identity-auth-content > * {
  margin-inline: auto;
}

.identity-auth-step-enter-active,
.identity-auth-step-leave-active {
  transition: opacity 180ms ease, transform 180ms ease;
}

.identity-auth-step-enter-from {
  opacity: 0;
  transform: translateX(1.25rem);
}

.identity-auth-step-leave-to {
  opacity: 0;
  transform: translateX(-1.25rem);
}

.identity-auth-form-shell {
  background: color-mix(
    in srgb,
    var(--identity-auth-card) 94%,
    var(--identity-auth-bg)
  );
  border: 1px solid color-mix(in srgb, var(--identity-auth-border) 78%, #fff);
  border-radius: 1rem;
  box-shadow: 0 0.75rem 2rem
    color-mix(in srgb, var(--identity-auth-text) 8%, transparent);
  padding: 1.75rem;
}

.identity-auth-loading-shell {
  min-height: 20rem;
  justify-content: center;
}

.identity-auth-return-state {
  align-items: center;
  display: flex;
  justify-content: center;
  min-height: 20rem;
  width: min(100%, 26rem);
}

.identity-auth-content:has(.identity-auth-return-state) > .identity-attribution {
  display: none;
}

.identity-auth-form-header {
  align-items: center;
  display: flex;
  flex-direction: column;
  text-align: center;
}

.identity-auth-form-header h1 {
  font-size: 1.25rem;
  font-weight: 700;
  margin: 0;
}

.identity-auth-form-header > p {
  color: var(--identity-auth-text);
  font-size: 1rem;
  font-weight: 600;
  margin: 0.5rem 0 0;
}

.identity-auth-form-header .identity-auth-form-header-description {
  color: var(--identity-auth-muted);
  font-size: 0.9rem;
  font-weight: 400;
  line-height: 1.5;
  margin-top: 0.25rem;
}

.identity-auth-form-header .identity-auth-form-header-welcome {
  color: var(--identity-auth-text);
  font-size: 0.95rem;
  font-weight: 500;
  line-height: 1.5;
  margin-top: 0.75rem;
  max-width: 32rem;
}

.identity-auth-form-header .identity-auth-form-header-link {
  color: var(--identity-auth-muted);
  font-size: 0.9rem;
  font-weight: 400;
  margin: 0.75rem 0 0;
}

.identity-auth-card {
  box-sizing: border-box;
  width: min(100%, 28rem);
  border: 1px solid var(--identity-auth-border);
  border-radius: 1rem;
  background: var(--identity-auth-card);
  box-shadow: 0 1rem 3rem color-mix(in srgb, var(--identity-auth-text) 8%, transparent);
  padding: 2rem;
}

.identity-auth-form {
  display: grid;
  gap: 1rem;
}

.identity-auth-field {
  display: grid;
  gap: 0.4rem;
  font-size: 0.9rem;
  font-weight: 650;
}

.identity-auth-field input {
  min-height: 2.75rem;
  box-sizing: border-box;
  border: 1px solid var(--identity-auth-input-border);
  background: var(--identity-auth-card);
  border-radius: 0.65rem;
  padding: 0.7rem 0.8rem;
  color: inherit;
  font: inherit;
  font-weight: 400;
}

.identity-auth-field input:focus {
  border-color: var(--identity-auth-accent);
  outline: 3px solid
    color-mix(in srgb, var(--identity-auth-accent) 18%, transparent);
}

.identity-auth-button {
  min-height: 2.75rem;
  border: 0;
  border-radius: 0.65rem;
  background: var(--identity-auth-accent);
  color: #fff;
  cursor: pointer;
  font: inherit;
  font-weight: 700;
}

.identity-auth-button:disabled {
  cursor: wait;
  opacity: 0.65;
}

.identity-auth-error,
.identity-auth-success,
.identity-auth-status {
  border-radius: 0.65rem;
  margin: 0;
  padding: 0.75rem;
  font-size: 0.9rem;
  line-height: 1.4;
}

.identity-auth-error {
  background: #fff0f0;
  color: #9f2525;
}

.identity-auth-success {
  background: #edf9f1;
  color: #14532d;
  border: 1px solid #86b89a;
}

.dark .identity-auth-success {
  background: #123524;
  color: #dcfce7;
  border-color: #40825b;
}

.identity-auth-status {
  background: var(--identity-auth-status);
  color: var(--identity-auth-muted);
}

.identity-auth-demo {
  display: grid;
  gap: 1rem;
  margin-top: 1.25rem;
  border-top: 1px solid var(--identity-auth-border);
  padding-top: 1.25rem;
}

.identity-auth-form + .identity-auth-demo {
  margin-top: 1.5rem;
}

.identity-auth-button--secondary {
  background: #172033;
}

.identity-auth-button--google {
  background: var(--identity-auth-card);
  border: 1px solid var(--identity-auth-input-border);
  color: var(--identity-auth-text);
}

.identity-auth-divider {
  color: var(--identity-auth-muted);
  font-size: 0.8rem;
  text-align: center;
}

.identity-auth-checkbox {
  align-items: flex-start;
  color: var(--identity-auth-muted);
  display: flex;
  font-size: 0.85rem;
  gap: 0.6rem;
  line-height: 1.45;
}

.identity-auth-checkbox input {
  margin-top: 0.2rem;
}
.identity-auth-checkbox a {
  color: var(--identity-auth-accent);
}

.identity-auth-expiry {
  margin: 0;
  color: var(--identity-auth-muted);
  font-size: 0.85rem;
  line-height: 1.45;
}

.identity-auth-field input[readonly] {
  background: var(--identity-auth-bg);
  color: var(--identity-auth-muted);
}

.identity-auth-links {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1rem;
  justify-content: center;
  margin: 1.25rem 0 0;
  font-size: 0.9rem;
}

.identity-auth-links a {
  color: var(--identity-auth-accent);
  text-decoration: none;
}

.identity-auth-links a:hover {
  text-decoration: underline;
}

.identity-auth-return-link {
  color: var(--identity-auth-accent);
  font-weight: 600;
  text-decoration: underline;
  text-decoration-color: currentColor;
  text-underline-offset: 0.2em;
}

.identity-auth-return-link:focus-visible {
  border-radius: 0.2rem;
  outline: 2px solid var(--identity-auth-accent);
  outline-offset: 3px;
}

.identity-auth-background--slate .identity-auth-header {
  background: color-mix(in srgb, var(--identity-auth-card) 72%, transparent);
  backdrop-filter: blur(14px);
}

.identity-auth-background--slate .identity-auth-form-shell {
  border-radius: 1.25rem;
  box-shadow: 0 1.5rem 4rem color-mix(in srgb, var(--identity-auth-accent) 14%, transparent);
}

.identity-auth-background--slate .identity-auth-form-header h1 {
  letter-spacing: -0.02em;
}

.identity-auth-background--indigo {
  background:
    radial-gradient(circle at 12% 14%, color-mix(in srgb, var(--identity-auth-accent) 12%, transparent), transparent 34%),
    var(--identity-auth-bg);
}

.identity-auth-background--emerald {
  background:
    radial-gradient(circle at 88% 12%, color-mix(in srgb, var(--identity-auth-accent) 14%, transparent), transparent 32%),
    var(--identity-auth-bg);
}

.identity-auth-background--sunset {
  background:
    radial-gradient(circle at 50% 0, color-mix(in srgb, var(--identity-auth-accent) 16%, transparent), transparent 38%),
    var(--identity-auth-bg);
}

.dark .identity-auth-background--slate .identity-auth-header {
  background: color-mix(in srgb, var(--identity-auth-bg) 78%, transparent);
  border-bottom-color: color-mix(in srgb, var(--identity-auth-border) 80%, transparent);
}

.dark .identity-auth-background--slate .identity-auth-form-shell {
  border-color: color-mix(in srgb, var(--identity-auth-accent) 30%, transparent);
  box-shadow: 0 1.5rem 4rem color-mix(in srgb, var(--identity-auth-accent) 16%, transparent);
}
</style>

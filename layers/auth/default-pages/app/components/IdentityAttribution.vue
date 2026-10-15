<script setup lang="ts">
defineProps<{
  termsUrl?: string | null
  privacyUrl?: string | null
}>()
</script>

<template>
  <footer class="identity-attribution">
    <nav
      v-if="termsUrl || privacyUrl"
      aria-label="Legal"
      class="identity-attribution-legal"
    >
      <a
        v-if="termsUrl"
        :href="termsUrl"
        target="_blank"
        rel="noopener noreferrer"
      >Terms of Service</a>
      <span v-if="termsUrl && privacyUrl">·</span>
      <a
        v-if="privacyUrl"
        :href="privacyUrl"
        target="_blank"
        rel="noopener noreferrer"
      >Privacy Policy</a>
    </nav>
    <span v-else />
    <a
      href="https://github.com/talredservices/identity"
      target="_blank"
      rel="noopener noreferrer"
      class="identity-attribution-credit"
    >
      <UIcon name="i-simple-icons-github" />
      Talred by Redouane Taleb
    </a>
  </footer>
</template>

<style scoped>
.identity-attribution {
  align-items: center;
  color: var(--identity-auth-muted, var(--ui-text-muted));
  display: flex;
  font-size: 0.75rem;
  justify-content: space-between;
  margin-top: 1rem;
  min-height: 1.25rem;
  width: 100%;
}

.identity-attribution a {
  color: inherit;
  text-decoration: none;
}

.identity-attribution a:hover { text-decoration: underline; }

.identity-attribution-legal,
.identity-attribution-credit {
  align-items: center;
  display: inline-flex;
  gap: 0.35rem;
}

.identity-attribution-credit :deep(svg) { height: 0.85rem; width: 0.85rem; }

@media (max-width: 40rem) {
  .identity-attribution {
    align-items: flex-start;
    flex-direction: column;
    gap: 0.5rem;
  }
}
</style>

<script setup lang="ts">
const colorMode = useColorMode()
const { locale } = useI18n()
const color = computed(() =>
  colorMode.value === 'dark' ? '#1b1718' : 'white'
)

useHead({
  meta: [
    { charset: 'utf-8' },
    { name: 'viewport', content: 'width=device-width, initial-scale=1' },
    { key: 'theme-color', name: 'theme-color', content: color }
  ],
  htmlAttrs: {
    lang: locale
  }
})

const title = 'Talred Identity | Authentication and project access'
const description
  = 'Talred Identity provides secure authentication, account management, project access, roles, permissions, and confidential client management.'

useSeoMeta({
  title,
  description,
  ogTitle: title,
  ogDescription: description,
  twitterCard: 'summary'
})
</script>

<template>
  <UApp>
    <NuxtLoadingIndicator />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>

<style lang="css">
:root {
  --ui-text-muted: var(--color-gray-700);
}

.dark {
  --ui-text-muted: var(--color-gray-300);
}
</style>

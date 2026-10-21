<script setup lang="ts">
definePageMeta({
  layout: 'identity-auth',
  middleware: 'identity-live-auth'
})
</script>

<template>
  <IdentityHostedLoginPage />
</template>

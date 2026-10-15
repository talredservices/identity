<script setup lang="ts">
definePageMeta({ layout: 'identity-auth' })
</script>

<template>
  <IdentityAuthCard
    title="Identity session ready"
    description="Your sign-in was successful. Return to the Talred application you were accessing to continue."
  >
    <div class="mt-6 flex flex-wrap gap-3">
      <UButton
        label="Sign out"
        color="neutral"
        variant="outline"
        to="/auth/logout"
      />
    </div>
  </IdentityAuthCard>
</template>

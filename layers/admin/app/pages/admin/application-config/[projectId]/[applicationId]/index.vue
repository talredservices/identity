<script setup lang="ts">
import HostedApplicationConfigPage from '#admin/app/components/identity/HostedApplicationConfigPage.vue'

definePageMeta({ layout: 'identity-admin', middleware: ['identity-admin'] })

const route = useRoute()
const projectId = computed(() => String(route.params.projectId))
const applicationId = computed(() => String(route.params.applicationId))
</script>

<template>
  <HostedApplicationConfigPage
    :project-id="projectId"
    :application-id="applicationId"
    section="general"
  />
</template>

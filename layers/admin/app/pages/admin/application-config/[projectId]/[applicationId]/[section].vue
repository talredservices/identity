<script setup lang="ts">
import HostedApplicationConfigPage from '#admin/app/components/identity/HostedApplicationConfigPage.vue'
import type { IdentityHostedApplicationConfigSection } from '#admin/app/composables/useIdentityHostedApplicationConfig'

definePageMeta({ layout: 'identity-admin', middleware: ['identity-admin'] })

const route = useRoute()
const localePath = useLocalePath()
const projectId = computed(() => String(route.params.projectId))
const applicationId = computed(() => String(route.params.applicationId))
const validSections: IdentityHostedApplicationConfigSection[] = ['general', 'security', 'clients', 'redirects', 'availability']
const section = computed<IdentityHostedApplicationConfigSection>(() => {
  const value = Array.isArray(route.params.section) ? route.params.section[0] : route.params.section
  return validSections.includes(value as IdentityHostedApplicationConfigSection)
    ? value as IdentityHostedApplicationConfigSection
    : 'general'
})

const rawSection = Array.isArray(route.params.section) ? route.params.section[0] : route.params.section
if (rawSection === 'overview' || rawSection === 'integration') {
  await navigateTo(
    localePath(`/admin/application-config/${projectId.value}/${applicationId.value}/general`),
    { replace: true }
  )
}
</script>

<template>
  <HostedApplicationConfigPage
    :project-id="projectId"
    :application-id="applicationId"
    :section="section"
  />
</template>

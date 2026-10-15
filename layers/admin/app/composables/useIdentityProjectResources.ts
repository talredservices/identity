import type { Ref } from 'vue'
import type {
  IdentityClient,
  IdentityApplicationListItem,
  IdentityHostedApplication,
  IdentityProject,
  IdentityProjectDetails
} from '../../types/identity-access'

export type IdentityProjectResources = {
  project: IdentityProject
  clients: IdentityClient[]
  applications: IdentityHostedApplication[]
}

export type IdentityClientDirectoryEntry = {
  client: IdentityClient
  project: IdentityProject
  applications: IdentityHostedApplication[]
}

export type IdentityApplicationDirectoryEntry = IdentityApplicationListItem

export async function useIdentityProjectResources(
  projects: Ref<IdentityProject[] | null | undefined>,
  key: string
) {
  const authenticatedFetch = useAuthenticatedFetch()
  const { data: projectResources, status: projectResourcesStatus, error: projectResourcesError, refresh: refreshProjectResources } = await useAsyncData<IdentityProjectResources[]>(
    key,
    async () => Promise.all((projects.value ?? []).map(async (project): Promise<IdentityProjectResources> => {
      try {
        const details = await authenticatedFetch<IdentityProjectDetails>(`/api/identity/projects/${project.id}`)
        return {
          project,
          clients: details.clients,
          applications: details.hosted_applications
        }
      } catch {
        return { project, clients: [], applications: [] }
      }
    })),
    { watch: [projects], default: () => [] }
  )

  const clientEntries = computed<IdentityClientDirectoryEntry[]>(() => (projectResources.value ?? []).flatMap(resources => resources.clients.map(client => ({
    client,
    project: resources.project,
    applications: resources.applications.filter(application => (
      application.primary_client_id === client.id || application.sandbox_client_id === client.id
    ))
  }))))

  const applicationEntries = computed<IdentityApplicationDirectoryEntry[]>(() => (projectResources.value ?? []).flatMap(resources => resources.applications.map(application => ({
    application,
    project: resources.project,
    clients: resources.clients.filter(client => (
      client.id === application.primary_client_id || client.id === application.sandbox_client_id
    ))
  }))))

  return {
    projectResources,
    projectResourcesStatus,
    projectResourcesError,
    refreshProjectResources,
    clientEntries,
    applicationEntries
  }
}

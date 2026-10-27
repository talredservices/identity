<script setup lang="ts">
import type { ComputedRef } from 'vue'
import { hostedModalThemeStyle } from '../utils/hosted-modal-theme'

type HostedThemeBrand = {
  presentation: {
    accentColor?: string | null
    designTokens?: Record<string, string>
  }
}

const props = defineProps<{
  open: boolean
  accepted: boolean
  termsUrl?: string | null
  privacyUrl?: string | null
  description: string
  confirmLabel: string
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'update:accepted': [value: boolean]
  'confirm': []
}>()

const brand = inject<ComputedRef<HostedThemeBrand | null>>(
  'identity-auth-brand',
  computed(() => null)
)
const colorMode = useColorMode()
const modalContent = computed(() => ({
  disableOutsidePointerEvents: true,
  style: hostedModalThemeStyle(
    brand.value?.presentation,
    colorMode.value === 'dark' ? 'dark' : 'light'
  )
}))

const consentLabel = computed(() => props.privacyUrl
  ? 'I have read and agree to the Terms of Service and Privacy Policy.'
  : 'I have read and agree to the Terms of Service.')
</script>

<template>
  <UModal
    :open="open"
    :content="modalContent"
    title="Review and accept"
    :description="description"
    @update:open="emit('update:open', $event)"
  >
    <template #body>
      <div class="space-y-5 sm:space-y-6">
        <div class="grid gap-3 sm:grid-cols-2">
          <a
            v-if="termsUrl"
            :href="termsUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="group flex min-w-0 items-center gap-3 rounded-xl border border-default bg-elevated p-4 transition-colors hover:border-primary"
          >
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-primary">
              <UIcon
                name="i-lucide-file-text"
                class="size-5"
                aria-hidden="true"
              />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-medium text-highlighted">Terms of Service</span>
              <span class="mt-1 block text-xs text-muted">Open policy</span>
            </span>
            <UIcon
              name="i-lucide-arrow-up-right"
              class="size-4 shrink-0 text-muted transition-colors group-hover:text-primary"
              aria-hidden="true"
            />
          </a>
          <a
            v-if="privacyUrl"
            :href="privacyUrl"
            target="_blank"
            rel="noopener noreferrer"
            class="group flex min-w-0 items-center gap-3 rounded-xl border border-default bg-elevated p-4 transition-colors hover:border-primary"
          >
            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-primary">
              <UIcon
                name="i-lucide-lock-keyhole"
                class="size-5"
                aria-hidden="true"
              />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block text-sm font-medium text-highlighted">Privacy Policy</span>
              <span class="mt-1 block text-xs text-muted">Open policy</span>
            </span>
            <UIcon
              name="i-lucide-arrow-up-right"
              class="size-4 shrink-0 text-muted transition-colors group-hover:text-primary"
              aria-hidden="true"
            />
          </a>
        </div>

        <div class="rounded-xl border border-default bg-muted p-4 sm:p-5">
          <UCheckbox
            :model-value="accepted"
            :label="consentLabel"
            @update:model-value="emit('update:accepted', Boolean($event))"
          />
        </div>
      </div>
    </template>
    <template #footer>
      <div class="flex w-full flex-col-reverse gap-2 sm:flex-row sm:justify-end sm:gap-3">
        <UButton
          label="Cancel"
          color="neutral"
          variant="outline"
          class="w-full justify-center sm:w-auto"
          :disabled="loading"
          @click="emit('update:open', false)"
        />
        <UButton
          :label="confirmLabel"
          icon="i-lucide-check"
          :disabled="!accepted"
          :loading="loading"
          class="w-full justify-center sm:w-auto"
          @click="emit('confirm')"
        />
      </div>
    </template>
  </UModal>
</template>

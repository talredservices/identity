<script setup lang="ts">
const props = withDefaults(defineProps<{
  logoUrl?: string | null
  accentColor?: string | null
  size?: 'header' | 'compact' | 'form'
}>(), {
  logoUrl: null,
  accentColor: null,
  size: 'form'
})

const brandMarkColor = computed(() =>
  /^#[\da-f]{6}$/i.test(props.accentColor ?? '') ? props.accentColor! : '#16937d'
)
</script>

<template>
  <span
    class="identity-auth-brand-mark"
    :class="[
      `identity-auth-brand-mark--${size}`,
      { 'identity-auth-brand-mark--custom': logoUrl }
    ]"
    aria-hidden="true"
  >
    <img
      v-if="logoUrl"
      :src="logoUrl"
      alt=""
    >
    <span
      v-else
      class="identity-auth-brand-mark-image"
      :style="{ '--identity-auth-brand-mark-color': brandMarkColor }"
    />
  </span>
</template>

<style scoped>
.identity-auth-brand-mark {
  align-items: center;
  box-sizing: border-box;
  display: inline-flex;
  flex: none;
  justify-content: center;
}

.identity-auth-brand-mark--custom {
  background: var(--ui-color-primary-500);
  border-radius: 9999px;
  color: #fff;
  overflow: hidden;
}

.identity-auth-brand-mark--header {
  height: 2.5rem;
  width: 2.5rem;
}

.identity-auth-brand-mark--compact {
  height: 1.75rem;
  width: 1.75rem;
}

.identity-auth-brand-mark--form {
  height: 2.75rem;
  width: 2.75rem;
}

.identity-auth-brand-mark--custom img {
  border-radius: inherit;
  display: block;
  height: 100%;
  object-fit: contain;
  width: 100%;
}

.identity-auth-brand-mark-image {
  background-color: var(--identity-auth-brand-mark-color);
  display: block;
  height: 100%;
  mask: url('../assets/branding/talred-mark.svg') center / contain no-repeat;
  -webkit-mask: url('../assets/branding/talred-mark.svg') center / contain no-repeat;
  width: 100%;
}
</style>

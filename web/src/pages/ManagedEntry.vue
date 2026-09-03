<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()
</script>

<template>
  <main class="min-h-screen bg-neutral-50 flex items-center justify-center p-5">
    <section class="w-full max-w-md rounded-xl border border-neutral-200 bg-surface shadow-sm p-7 text-center">
      <img src="/styles/logo.svg" :alt="auth.productName" class="w-14 h-14 mx-auto mb-4" />
      <h1 class="text-xl font-semibold text-neutral-900">{{ auth.productName }}</h1>
      <p class="mt-3 text-sm text-neutral-600">{{ t('managed.sign_in_via_revizior') }}</p>
      <a
        v-if="auth.returnUrl"
        :href="auth.returnUrl"
        class="mt-6 inline-flex h-10 items-center justify-center rounded-md bg-primary-600 px-5 text-sm font-medium text-white hover:bg-primary-700"
      >{{ t('managed.back_to_revizior') }}</a>
      <p v-else class="mt-6 text-sm text-danger-600">{{ t('managed.return_unavailable') }}</p>
    </section>
  </main>
</template>

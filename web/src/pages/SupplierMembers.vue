<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  supplierMembersApi,
  type SupplierMember,
  type SupplierMemberRole,
} from '@/api/supplierMembers'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const members = ref<SupplierMember[]>([])
const roles = ref<Record<number, SupplierMemberRole>>({})
const loading = ref(false)
const savingUserId = ref<number | null>(null)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    members.value = await supplierMembersApi.list()
    roles.value = Object.fromEntries(members.value.map(member => [member.user_id, member.role]))
  } catch (exception) {
    error.value = apiErrorMessage(exception)
  } finally {
    loading.value = false
  }
}

async function save(member: SupplierMember) {
  const role = roles.value[member.user_id]
  if (!role || role === member.role) return
  savingUserId.value = member.user_id
  error.value = ''
  try {
    const result = await supplierMembersApi.updateRole(member.user_id, role)
    members.value = result.members
    roles.value = Object.fromEntries(result.members.map(row => [row.user_id, row.role]))
    toast.success(t('supplier_members.saved'))
  } catch (exception) {
    roles.value[member.user_id] = member.role
    error.value = apiErrorMessage(exception)
  } finally {
    savingUserId.value = null
  }
}

async function remove(member: SupplierMember) {
  if (!window.confirm(t('supplier_members.remove_confirm', { email: member.email }))) return
  savingUserId.value = member.user_id
  error.value = ''
  try {
    await supplierMembersApi.remove(member.user_id)
    members.value = members.value.filter(row => row.user_id !== member.user_id)
    delete roles.value[member.user_id]
    toast.success(t('supplier_members.removed'))
  } catch (exception) {
    error.value = apiErrorMessage(exception)
  } finally {
    savingUserId.value = null
  }
}

function isSelf(member: SupplierMember): boolean {
  return member.user_id === auth.user?.id
}

onMounted(load)
</script>

<template>
  <div class="w-full">
    <header class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('supplier_members.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('supplier_members.subtitle') }}</p>
    </header>

    <div class="mb-4 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-800">
      {{ t('supplier_members.identity_hint') }}
    </div>
    <div v-if="error" class="mb-4 rounded-md border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700">
      {{ error }}
    </div>
    <div v-if="loading" class="py-12 text-center text-neutral-400">{{ t('common.loading') }}</div>
    <div v-else-if="members.length === 0" class="rounded-lg border border-dashed border-neutral-300 bg-surface p-8 text-center text-neutral-500">
      {{ t('supplier_members.empty') }}
    </div>
    <section v-else class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <div v-for="member in members" :key="member.user_id" class="flex flex-col gap-3 border-b border-neutral-100 p-4 last:border-b-0 sm:flex-row sm:items-center">
        <div class="min-w-0 flex-1">
          <div class="flex items-center gap-2">
            <span class="truncate font-medium">{{ member.name }}</span>
            <span v-if="isSelf(member)" class="rounded bg-primary-50 px-2 py-0.5 text-xs text-primary-700">{{ t('supplier_members.you') }}</span>
            <span v-if="!member.is_active" class="rounded bg-neutral-100 px-2 py-0.5 text-xs text-neutral-500">{{ t('supplier_members.inactive') }}</span>
          </div>
          <div class="truncate font-mono text-xs text-neutral-500">{{ member.email }}</div>
        </div>
        <div class="flex items-center gap-2">
          <select v-model="roles[member.user_id]" :disabled="isSelf(member) || savingUserId === member.user_id"
            class="h-9 min-w-40 rounded-md border border-neutral-300 bg-surface px-2 text-sm disabled:opacity-60">
            <option value="supplier_owner">{{ t('supplier_members.role_owner') }}</option>
            <option value="accountant">{{ t('supplier_members.role_accountant') }}</option>
            <option value="readonly">{{ t('supplier_members.role_readonly') }}</option>
          </select>
          <button type="button" :disabled="isSelf(member) || roles[member.user_id] === member.role || savingUserId !== null"
            class="h-9 cursor-pointer rounded-md bg-primary-600 px-3 text-sm font-medium text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-40"
            @click="save(member)">
            {{ t('common.save') }}
          </button>
          <button type="button" :disabled="isSelf(member) || savingUserId !== null"
            class="h-9 cursor-pointer rounded-md border border-danger-300 px-3 text-sm text-danger-600 hover:bg-danger-50 disabled:cursor-not-allowed disabled:opacity-40"
            @click="remove(member)">
            {{ t('supplier_members.remove') }}
          </button>
        </div>
      </div>
    </section>
  </div>
</template>

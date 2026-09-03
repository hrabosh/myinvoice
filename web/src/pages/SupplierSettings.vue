<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { settingsApi, type Supplier } from '@/api/settings'
import { useToast } from '@/composables/useToast'

/**
 * Fakturační identita aktuální firmy pro tenantového vlastníka.
 *
 * Managed režim nemá `/admin/settings` (to je platformní obrazovka), ale doklad
 * bez úplné adresy a DIČ vystavit nejde. Tahle stránka proto ukazuje jen to, co
 * je na daňovém dokladu potřeba, plus výchozí hodnoty nové faktury — ne celé
 * nastavení instalace.
 *
 * Země se needituje: přiřazuje ji provisioning podle organizace v ReviziORu
 * a její změna by rozhodila daňový režim už vystavených dokladů.
 */
const { t } = useI18n()
const toast = useToast()

const supplier = ref<Supplier | null>(null)
const loading = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
  company_name: '',
  street: '',
  city: '',
  zip: '',
  ic: '',
  dic: '',
  is_vat_payer: false,
  is_identified: false,
  email: '',
  phone: '',
  web: '',
  default_payment_due_days: 14,
  default_payment_due_unit: 'days' as 'days' | 'month',
  default_prices_include_vat: false,
})

/** Plátce a identifikovaná osoba se vylučují — § 6g–6l ZDPH. */
const vatMode = computed({
  get: (): 'payer' | 'identified' | 'none' => {
    if (form.value.is_vat_payer) return 'payer'

    return form.value.is_identified ? 'identified' : 'none'
  },
  set: (mode: 'payer' | 'identified' | 'none') => {
    form.value.is_vat_payer = mode === 'payer'
    form.value.is_identified = mode === 'identified'
  },
})

const missing = computed(() => {
  const fields: string[] = []
  if (!form.value.company_name.trim()) fields.push(t('supplier_settings.field_company_name'))
  if (!form.value.street.trim()) fields.push(t('supplier_settings.field_street'))
  if (!form.value.city.trim()) fields.push(t('supplier_settings.field_city'))
  if (!form.value.zip.trim()) fields.push(t('supplier_settings.field_zip'))
  if (form.value.is_vat_payer && !form.value.dic.trim()) fields.push(t('supplier_settings.field_dic'))

  return fields
})

onMounted(load)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await settingsApi.getSupplier()
    supplier.value = data
    form.value = {
      company_name: data.company_name ?? '',
      street: data.street ?? '',
      city: data.city ?? '',
      zip: data.zip ?? '',
      ic: data.ic ?? '',
      dic: data.dic ?? '',
      is_vat_payer: data.is_vat_payer === true,
      is_identified: data.is_identified === true,
      email: data.email ?? '',
      phone: data.phone ?? '',
      web: data.web ?? '',
      default_payment_due_days: data.default_payment_due_days ?? 14,
      default_payment_due_unit: data.default_payment_due_unit ?? 'days',
      default_prices_include_vat: data.default_prices_include_vat === true,
    }
  } catch (exception) {
    error.value = apiErrorMessage(exception)
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    // Posílá se jen to, co stránka edituje — zbytek nastavení firmy zůstává,
    // jak ho má instalace nebo provisioning.
    supplier.value = await settingsApi.updateSupplier({
      ...form.value,
      ic: form.value.ic.trim() || null,
      dic: form.value.dic.trim() || null,
      phone: form.value.phone.trim() || null,
      web: form.value.web.trim() || null,
    })
    toast.success(t('supplier_settings.saved'))
  } catch (exception) {
    error.value = apiErrorMessage(exception)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="w-full max-w-3xl">
    <header class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('supplier_settings.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('supplier_settings.subtitle') }}</p>
    </header>

    <div v-if="error" class="mb-4 rounded-md border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700">
      {{ error }}
    </div>
    <div v-else-if="missing.length > 0" class="mb-4 rounded-md border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('supplier_settings.incomplete', { fields: missing.join(', ') }) }}
    </div>

    <div v-if="loading" class="py-12 text-center text-neutral-400">{{ t('common.loading') }}</div>

    <form v-else class="space-y-6" @submit.prevent="save">
      <section class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-neutral-700">{{ t('supplier_settings.identity') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="sm:col-span-2 block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_company_name') }}</span>
            <input v-model="form.company_name" type="text" required maxlength="190"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
          <label class="sm:col-span-2 block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_street') }}</span>
            <input v-model="form.street" type="text" required maxlength="190"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_city') }}</span>
            <input v-model="form.city" type="text" required maxlength="120"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_zip') }}</span>
            <input v-model="form.zip" type="text" required maxlength="10"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_country') }}</span>
            <input :value="supplier?.country_iso ?? ''" type="text" disabled
              class="h-9 w-full rounded-md border border-neutral-200 bg-neutral-50 px-2 text-sm text-neutral-500">
            <span class="mt-1 block text-xs text-neutral-400">{{ t('supplier_settings.country_hint') }}</span>
          </label>
        </div>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-neutral-700">{{ t('supplier_settings.tax') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_ic') }}</span>
            <input v-model="form.ic" type="text" maxlength="20"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_dic') }}</span>
            <input v-model="form.dic" type="text" maxlength="20"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
          <label class="sm:col-span-2 block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_vat_mode') }}</span>
            <select v-model="vatMode" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
              <option value="none">{{ t('supplier_settings.vat_none') }}</option>
              <option value="identified">{{ t('supplier_settings.vat_identified') }}</option>
              <option value="payer">{{ t('supplier_settings.vat_payer') }}</option>
            </select>
          </label>
        </div>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-neutral-700">{{ t('supplier_settings.contact') }}</h2>
        <div class="grid gap-3 sm:grid-cols-3">
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_email') }}</span>
            <input v-model="form.email" type="email" maxlength="190"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_phone') }}</span>
            <input v-model="form.phone" type="text" maxlength="40"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_web') }}</span>
            <input v-model="form.web" type="text" maxlength="190"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
        </div>
      </section>

      <section class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-neutral-700">{{ t('supplier_settings.defaults') }}</h2>
        <div class="grid gap-3 sm:grid-cols-3">
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_due') }}</span>
            <input v-model.number="form.default_payment_due_days" type="number" min="1" max="365"
              class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
          </label>
          <label class="block text-sm">
            <span class="mb-1 block text-neutral-600">{{ t('supplier_settings.field_due_unit') }}</span>
            <select v-model="form.default_payment_due_unit" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm">
              <option value="days">{{ t('supplier_settings.due_days') }}</option>
              <option value="month">{{ t('supplier_settings.due_month') }}</option>
            </select>
          </label>
          <label class="flex items-end gap-2 text-sm">
            <input v-model="form.default_prices_include_vat" type="checkbox" class="mb-2 h-4 w-4">
            <span class="mb-2 text-neutral-600">{{ t('supplier_settings.field_prices_include_vat') }}</span>
          </label>
        </div>
      </section>

      <div class="flex items-center gap-3">
        <button type="submit" :disabled="saving"
          class="h-9 cursor-pointer rounded-md bg-primary-600 px-4 text-sm font-medium text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-40">
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
        <span class="text-xs text-neutral-400">{{ t('supplier_settings.scope_hint') }}</span>
      </div>
    </form>
  </div>
</template>

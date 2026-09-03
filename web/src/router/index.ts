import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import type { DeploymentModule, Permission } from '@/api/auth'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/components/layout/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '',                       name: 'home',           component: () => import('@/pages/Dashboard.vue') },
      { path: 'clients',                name: 'clients',        component: () => import('@/pages/clients/ClientList.vue') },
      { path: 'clients/new',            name: 'client-new',     component: () => import('@/pages/clients/ClientForm.vue'), meta: { requiresWrite: true, requiresSupplier: true } },
      { path: 'clients/:id(\\d+)',      name: 'client-detail',  component: () => import('@/pages/clients/ClientDetail.vue') },
      { path: 'clients/:id(\\d+)/edit', name: 'client-edit',    component: () => import('@/pages/clients/ClientForm.vue'), meta: { requiresWrite: true, requiresSupplier: true } },
      { path: 'projects',               name: 'projects',       component: () => import('@/pages/projects/ProjectList.vue') },
      { path: 'projects/new',           name: 'project-new',    component: () => import('@/pages/projects/ProjectForm.vue'), meta: { requiresWrite: true, requiresSupplier: true } },
      { path: 'projects/:id(\\d+)',     name: 'project-detail', component: () => import('@/pages/projects/ProjectDetail.vue') },
      { path: 'projects/:id(\\d+)/edit', name: 'project-edit',  component: () => import('@/pages/projects/ProjectForm.vue'), meta: { requiresWrite: true, requiresSupplier: true } },
      { path: 'invoices',               name: 'invoices',       component: () => import('@/pages/invoices/InvoiceList.vue') },
      { path: 'invoices/new',           name: 'invoice-new',    component: () => import('@/pages/invoices/InvoiceEditor.vue'), meta: { requiresWrite: true, requiresSupplier: true } },
      { path: 'invoices/:id(\\d+)',     name: 'invoice-detail', component: () => import('@/pages/invoices/InvoiceDetail.vue') },
      { path: 'invoices/:id(\\d+)/edit', name: 'invoice-edit',  component: () => import('@/pages/invoices/InvoiceEditor.vue'), meta: { requiresWrite: true, requiresSupplier: true } },
      // Přijaté faktury (fáze 1 integrace forku)
      { path: 'purchase-invoices',                 name: 'purchase-invoices',        component: () => import('@/pages/purchase-invoices/InvoiceList.vue'), meta: { module: 'purchaseInvoices' } },
      { path: 'purchase-invoices/export',          name: 'purchase-invoices-export', component: () => import('@/pages/purchase-invoices/Export.vue'), meta: { module: 'purchaseInvoices' } },
      { path: 'purchase-invoices/payment-orders',  name: 'purchase-invoices-payment-orders', component: () => import('@/pages/purchase-invoices/PaymentOrders.vue'), meta: { module: 'purchaseInvoices' } },
      { path: 'purchase-invoices/new',             name: 'purchase-invoice-new',     component: () => import('@/pages/purchase-invoices/InvoiceEditor.vue'), meta: { requiresWrite: true, requiresSupplier: true, module: 'purchaseInvoices' } },
      { path: 'purchase-invoices/:id(\\d+)',       name: 'purchase-invoice-detail',  component: () => import('@/pages/purchase-invoices/InvoiceDetail.vue'), meta: { module: 'purchaseInvoices' } },
      { path: 'purchase-invoices/:id(\\d+)/edit',  name: 'purchase-invoice-edit',    component: () => import('@/pages/purchase-invoices/InvoiceEditor.vue'), meta: { requiresWrite: true, requiresSupplier: true, module: 'purchaseInvoices' } },
      // Dokumenty (sekce Dokumenty — plán source/11)
      { path: 'documents',              name: 'documents',        component: () => import('@/pages/documents/DocumentsBrowser.vue'), meta: { module: 'documents' } },
      { path: 'documents/:id(\\d+)',    name: 'document-detail',  component: () => import('@/pages/documents/DocumentDetail.vue'), meta: { module: 'documents' } },
      // Kniha jízd (logbook) — auta, jízdy, tankování
      { path: 'logbook',                name: 'logbook',          component: () => import('@/pages/logbook/LogbookPage.vue'), meta: { module: 'logbook' } },
      { path: 'stats',                  name: 'stats',           component: () => import('@/pages/Stats.vue') },
      { path: 'purchase-stats',         name: 'purchase-stats',  component: () => import('@/pages/PurchaseStats.vue'), meta: { module: 'purchaseInvoices' } },
      // Sjednocená stránka „Bankovní účty" (Finance): výpisy + měny/účty + stavy + avíza.
      { path: 'bank',                   name: 'bank-statements', component: () => import('@/pages/bank/BankPage.vue') },
      { path: 'bank/:id(\\d+)',         name: 'bank-detail',     component: () => import('@/pages/bank/StatementDetail.vue') },
      // Admin (M6)
      { path: 'admin/activity-log',     name: 'activity-log',   component: () => import('@/pages/admin/ActivityLog.vue'), meta: { requiresPermission: 'platform_settings.manage' } },
      { path: 'admin/sent-emails',      name: 'sent-emails',    component: () => import('@/pages/admin/SentEmails.vue'), meta: { requiresPermission: 'platform_settings.manage' } },
      { path: 'admin/cron-jobs',        name: 'cron-jobs',      component: () => import('@/pages/admin/CronJobs.vue'),    meta: { requiresPermission: 'platform_settings.manage' } },
      { path: 'admin/users',            name: 'admin-users',    component: () => import('@/pages/admin/Users.vue'),       meta: { requiresPermission: 'platform_users.manage' } },
      { path: 'admin/settings',         name: 'admin-settings', component: () => import('@/pages/admin/Settings.vue'),    meta: { requiresPermission: 'platform_settings.manage' } },
      // Bývalá stránka Systém → Bankovní účty je nyní součástí /bank (Finance) jako záložky.
      // Redirect zachovává bookmarks vč. původního ?tab=.
      {
        path: 'admin/bank-accounts',
        name: 'admin-bank-accounts',
        redirect: to => ({
          path: '/bank',
          query: { tab: ['accounts', 'balances', 'email'].includes(String(to.query.tab)) ? String(to.query.tab) : 'accounts' },
        }),
      },
      { path: 'admin/bank-email-notices', name: 'admin-bank-email-notices', redirect: '/bank?tab=email' },
      // /admin/suppliers byla samostatná stránka — Suppliers jsou nyní embedded jako první tab v Codebooks.
      // Redirect zachovává bookmarks / staré odkazy.
      { path: 'admin/suppliers',        name: 'admin-suppliers', redirect: '/admin/codebooks' },
      { path: 'admin/codebooks',        name: 'admin-codebooks', component: () => import('@/pages/admin/Codebooks.vue'),  meta: { requiresPermission: 'platform_settings.manage' } },
      { path: 'admin/electronic-signatures', name: 'admin-electronic-signatures', component: () => import('@/pages/admin/ElectronicSignatures.vue'), meta: { requiresWrite: true, signingProfiles: true } },
      { path: 'admin/export',           name: 'admin-export',    component: () => import('@/pages/admin/Export.vue') },
      // Import dokladů (upload Pohoda/ISDOC/PDF + sken inboxu) je práce s daty, ne
      // konfigurace → účetní ano, readonly ne. Konfigurace integrací zůstává v
      // admin-only Integracích.
      { path: 'admin/import',           name: 'admin-import',    component: () => import('@/pages/admin/Imports.vue'),    meta: { requiresWrite: true, requiresSupplier: true } },
      { path: 'admin/integrations',     name: 'admin-integrations', component: () => import('@/pages/admin/Integrations.vue'), meta: { requiresPermission: 'platform_settings.manage' } },
      { path: 'crm',                    name: 'crm-dashboard',      component: () => import('@/pages/crm/CrmDashboard.vue') },
      { path: 'reports/dph',            name: 'reports-dph',        component: () => import('@/pages/reports/DphPriznaniReport.vue'), meta: { module: 'tax' } },
      { path: 'reports/kh',             name: 'reports-kh',         component: () => import('@/pages/reports/KontrolniHlaseniReport.vue'), meta: { module: 'tax' } },
      { path: 'reports/dph-book',       name: 'reports-dph-book',   component: () => import('@/pages/reports/DphBookReport.vue'), meta: { module: 'tax' } },
      { path: 'reports/shv',            name: 'reports-shv',        component: () => import('@/pages/reports/SouhrnneHlaseniReport.vue'), meta: { module: 'tax' } },
      { path: 'reports/income-tax',     name: 'reports-income-tax', component: () => import('@/pages/reports/IncomeTaxReport.vue'), meta: { module: 'tax' } },
      { path: 'reports/submissions',    name: 'reports-submissions', component: () => import('@/pages/reports/TaxSubmissions.vue'), meta: { module: 'tax' } },
      { path: 'reports/monthly-export', name: 'reports-monthly-export', component: () => import('@/pages/reports/MonthlyExportReport.vue'), meta: { module: 'tax' } },
      { path: 'reports/oss',            name: 'reports-oss',        component: () => import('@/pages/reports/OssReport.vue'), meta: { requiresOss: true, module: 'tax' } },
      { path: 'tax',                    name: 'tax-optimizer',      component: () => import('@/pages/tax/TaxOptimizer.vue'), meta: { module: 'tax' } },
      { path: 'admin/email-templates',  name: 'admin-email-templates', component: () => import('@/pages/admin/EmailTemplates.vue'), meta: { requiresPermission: 'platform_settings.manage' } },
      // Sekce E-maily — záložky: Odeslané / Šablony / Elektronické podpisy (vzor Codebooks)
      { path: 'admin/emails',           name: 'admin-emails',    component: () => import('@/pages/admin/Emails.vue'), meta: { requiresPermission: 'platform_settings.manage' } },
      { path: 'admin/approvals',        name: 'admin-approvals', component: () => import('@/pages/admin/Approvals.vue'), meta: { requiresPermission: 'platform_settings.manage' } },
      { path: 'admin/price-list',       name: 'admin-price-list', component: () => import('@/pages/admin/PriceList.vue'), meta: { requiresPermission: 'price_list.manage', requiresSupplier: true } },
      { path: 'admin/price-list/new',   name: 'admin-price-list-new', component: () => import('@/pages/admin/PriceListForm.vue'), meta: { requiresPermission: 'price_list.manage', requiresSupplier: true } },
      { path: 'admin/price-list/:id(\\d+)/edit', name: 'admin-price-list-edit', component: () => import('@/pages/admin/PriceListForm.vue'), meta: { requiresPermission: 'price_list.manage', requiresSupplier: true } },
      { path: 'settings/supplier',       name: 'supplier-settings', component: () => import('@/pages/SupplierSettings.vue'), meta: { requiresPermission: 'supplier_settings.manage', requiresSupplier: true } },
      { path: 'settings/members',        name: 'supplier-members', component: () => import('@/pages/SupplierMembers.vue'), meta: { requiresPermission: 'supplier_members.manage', requiresSupplier: true } },
      { path: 'recurring',              name: 'recurring',        component: () => import('@/pages/recurring/RecurringList.vue') },
      { path: 'recurring/new',          name: 'recurring-new',    component: () => import('@/pages/recurring/RecurringForm.vue'), meta: { requiresWrite: true, requiresSupplier: true } },
      { path: 'recurring/:id(\\d+)',    name: 'recurring-detail', component: () => import('@/pages/recurring/RecurringDetail.vue') },
      { path: 'recurring/:id(\\d+)/edit', name: 'recurring-edit', component: () => import('@/pages/recurring/RecurringForm.vue'), meta: { requiresWrite: true, requiresSupplier: true } },
      { path: 'admin/update',           name: 'admin-update',    component: () => import('@/pages/admin/Update.vue'),    meta: { requiresPermission: 'platform_update.manage', module: 'selfUpdate' } },
      { path: 'admin/upgrade',          name: 'admin-myucto-upgrade', component: () => import('@/pages/admin/MyuctoUpgrade.vue'), meta: { requiresPermission: 'platform_update.manage', module: 'myuctoUpgrade' } },
      // Staré profilové URL zůstávají funkční, ale UI je zobrazuje jako záložky
      // na /profile/password. Redirecty zachovávají ostatní query stringy.
      { path: 'profile/totp',           name: 'profile-totp',          redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'totp' } }) },
      { path: 'profile/password',       name: 'profile-password',      component: () => import('@/pages/PasswordChange.vue') },
      { path: 'profile/api-tokens',     name: 'profile-api-tokens',    component: () => import('@/pages/ApiTokens.vue') },
      { path: 'profile/passkeys',       name: 'profile-passkeys',      redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'passkeys' } }) },
      { path: 'profile/session-lock',   name: 'profile-session-lock',  redirect: (to) => ({ path: '/profile/password', query: { ...to.query, tab: 'session-lock' } }) },
      { path: 'profile/signing-profiles', name: 'profile-signing-profiles', redirect: '/admin/electronic-signatures' },
    ],
  },
  { path: '/login',  name: 'login',  component: () => import('@/pages/Login.vue'),          meta: { public: true } },
  { path: '/setup',  name: 'setup',  component: () => import('@/pages/Setup.vue'),          meta: { public: true } },
  { path: '/setup-mfa', name: 'setup-mfa', component: () => import('@/pages/ForcedMfaSetup.vue'), meta: { requiresAuth: true, mfaSetupOnly: true } },
  { path: '/setup-totp', name: 'setup-totp', redirect: { path: '/setup-mfa', query: { method: 'totp' } } },
  { path: '/forgot', name: 'forgot', component: () => import('@/pages/ForgotPassword.vue'), meta: { public: true } },
  { path: '/reset',  name: 'reset',  component: () => import('@/pages/ResetPassword.vue'),  meta: { public: true } },
  { path: '/managed', name: 'managed-entry', component: () => import('@/pages/ManagedEntry.vue'), meta: { public: true } },
  { path: '/approval/:token([a-f0-9]{32,128})', name: 'approval',
    component: () => import('@/pages/ApprovalPublic.vue'), meta: { public: true } },
  { path: '/work-report/:token([a-f0-9]{32,128})', name: 'work-report-tracking',
    component: () => import('@/pages/WorkReportTrackingPublic.vue'), meta: { public: true } },
  // Web faktura — veřejný náhled vystavené faktury (singular /invoice/…, interní UI je /invoices/…)
  { path: '/invoice/:token([a-f0-9]{32,128})', name: 'invoice-public',
    component: () => import('@/pages/InvoicePublic.vue'), meta: { public: true } },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFound.vue'),
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  // Scroll-to-top při navigaci sidebar linky; respektuj #hash a back/forward
  scrollBehavior(_to, _from, savedPosition) {
    if (savedPosition) return savedPosition
    if (_to.hash) return { el: _to.hash, behavior: 'smooth' }
    return { top: 0, left: 0 }
  },
})

/**
 * Bezpečný fallback při zamítnutí. Vrací `true` (pusť dál), když už cílíme na
 * `home` — jinak by vznikla NEKONEČNÁ smyčka, kdyby dashboard sám propadl
 * některým z gatů níž (přesně tak zamrzl prohlížeč u uživatele bez přístupu
 * k firmě v MyÚčtu). Radši pustit dál a nechat stránku zobrazit prázdný stav
 * nebo chybu z API, než točit prohlížeč donekonečna.
 */
function denyFallback(toName: unknown) {
  return toName === 'home' ? true : { name: 'home' }
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.setupStatus === null) {
    try {
      await auth.fetchSetupStatus()
    } catch {
      // ignore
    }
  }

  if (auth.needsSetup && to.name !== 'setup') {
    return { name: 'setup' }
  }
  if (!auth.needsSetup && to.name === 'setup') {
    return { name: auth.isManaged ? 'managed-entry' : 'login' }
  }

  if (auth.isManaged && ['login', 'forgot', 'reset'].includes(String(to.name))) {
    return { name: 'managed-entry' }
  }
  if (!auth.isManaged && to.name === 'managed-entry') {
    return { name: 'login' }
  }

  const requiredModule = to.matched.find((r) => typeof r.meta.module === 'string')?.meta.module
  if (typeof requiredModule === 'string'
      && !auth.moduleEnabled(requiredModule as DeploymentModule)) {
    return denyFallback(to.name)
  }

  const requiresAuth = to.matched.some((r) => r.meta.requiresAuth)
  if (requiresAuth && !auth.isAuthenticated) {
    // Rozhoduje stav storu, ne návratová hodnota: refresh() při síťovém výpadku
    // vrací false, ale známou identitu si záměrně drží.
    await auth.refresh()
    if (!auth.isAuthenticated) return { name: 'login' }
  }
  if (requiresAuth && auth.lockedSession) {
    useSessionSecurityStore().apply(auth.lockedSession)
    return true
  }
  if (requiresAuth) {
    const sessionSecurity = useSessionSecurityStore()
    if (sessionSecurity.state === null) {
      await sessionSecurity.refresh()
    }
  }

  // Setup session nemá přístup k business routám, dokud uživatel nedokončí MFA.
  const mustSetupMfa = auth.mustSetupMfa || auth.mustSetupTotp
  if (auth.isAuthenticated && mustSetupMfa && to.name !== 'setup-mfa' && requiresAuth) {
    return { name: 'setup-mfa' }
  }
  if (auth.isAuthenticated && !mustSetupMfa && to.name === 'setup-mfa') {
    return { name: 'home' }
  }

  // Admin-only stránky
  const adminOnly = to.matched.some((r) => r.meta.adminOnly)
  if (adminOnly && auth.user?.role !== 'admin') {
    return denyFallback(to.name)
  }

  const requiredPermission = to.matched
    .map((route) => route.meta.requiresPermission as Permission | undefined)
    .find((permission): permission is Permission => permission !== undefined)
  if (requiredPermission && !auth.hasPermission(requiredPermission)) {
    return denyFallback(to.name)
  }

  // Zápisové stránky (zakládání/editace dokladů, klientů, zakázek, recurring).
  // readonly smí jen číst/exportovat → na write routes ho přesměrujeme na dashboard.
  const requiresWrite = to.matched.some((r) => r.meta.requiresWrite)
  if (requiresWrite && !auth.canWrite) {
    return denyFallback(to.name)
  }

  // Onboarding gate: pokud uživatel v úvodním nastavení přeskočil dodavatele, nemá v DB
  // žádného supplier-a. Data (klienti, faktury, currencies) jsou supplier-scoped, takže
  // zakládací formuláře by jinak spadly na matoucí „Validace selhala" (#151). Místo toho
  // ho pošleme na dashboard, kde se zobrazí výzva k vytvoření prvního dodavatele.
  const requiresSupplier = to.matched.some((r) => r.meta.requiresSupplier)
  if (requiresSupplier && auth.isAuthenticated && !useSupplierStore().hasSupplier) {
    return denyFallback(to.name)
  }

  // OSS gate: režim je opt-in v nastavení firmy (default vypnuto). Bez registrace nemá
  // kvartální přehled co ukázat, takže na něj nepustíme ani přes přímou URL.
  const requiresOss = to.matched.some((r) => r.meta.requiresOss)
  if (requiresOss && auth.isAuthenticated && useSupplierStore().currentSupplier?.oss_enabled !== true) {
    return denyFallback(to.name)
  }

  const signingProfiles = to.matched.some((r) => r.meta.signingProfiles)
  if (signingProfiles && auth.user?.role !== 'admin' && auth.user?.role !== 'accountant') {
    return denyFallback(to.name)
  }

  return true
})

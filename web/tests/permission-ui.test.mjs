import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)

test('price list navigation and routes use server permissions instead of the legacy admin role', async () => {
  const [auth, router, layout] = await Promise.all([
    readFile(new URL('stores/auth.ts', root), 'utf8'),
    readFile(new URL('router/index.ts', root), 'utf8'),
    readFile(new URL('components/layout/AppLayout.vue', root), 'utf8'),
  ])

  assert.match(auth, /permissions\.value = data\.permissions \|\| \[\]/)
  assert.match(auth, /function hasPermission\(permission: Permission\)/)
  assert.match(router, /requiresPermission: 'price_list\.manage'/)
  assert.match(router, /!auth\.hasPermission\(requiredPermission\)/)
  assert.match(layout, /auth\.hasPermission\('price_list\.manage'\)/)
  assert.doesNotMatch(layout, /isAdmin \? \[\{ to: '\/admin\/price-list'/)
})

test('company member management is tenant scoped and permission driven', async () => {
  const [api, page, router, layout] = await Promise.all([
    readFile(new URL('api/supplierMembers.ts', root), 'utf8'),
    readFile(new URL('pages/SupplierMembers.vue', root), 'utf8'),
    readFile(new URL('router/index.ts', root), 'utf8'),
    readFile(new URL('components/layout/AppLayout.vue', root), 'utf8'),
  ])

  assert.match(api, /\/settings\/supplier\/members/)
  assert.doesNotMatch(api, /\/admin\/users/)
  assert.match(page, /supplierMembersApi\.updateRole/)
  assert.match(page, /supplierMembersApi\.remove/)
  assert.match(router, /requiresPermission: 'supplier_members\.manage'/)
  assert.match(layout, /auth\.hasPermission\('supplier_members\.manage'\)/)
})

test('tenant invoicing settings are permission driven and never touch platform settings', async () => {
  const [page, router, layout, cs, en] = await Promise.all([
    readFile(new URL('pages/SupplierSettings.vue', root), 'utf8'),
    readFile(new URL('router/index.ts', root), 'utf8'),
    readFile(new URL('components/layout/AppLayout.vue', root), 'utf8'),
    readFile(new URL('i18n/cs.json', root), 'utf8'),
    readFile(new URL('i18n/en.json', root), 'utf8'),
  ])

  // Tenantová obrazovka jde na `/settings/supplier`, ne na platformní `/admin/settings`.
  assert.match(page, /settingsApi\.getSupplier/)
  assert.match(page, /settingsApi\.updateSupplier/)
  // Tenantová stránka nesmí viset na platformním oprávnění ani na admin API.
  assert.doesNotMatch(page, /platform_settings/)
  assert.doesNotMatch(page, /adminApi/)
  assert.match(router, /path: 'settings\/supplier'/)
  assert.match(router, /requiresPermission: 'supplier_settings\.manage'/)
  assert.match(layout, /auth\.hasPermission\('supplier_settings\.manage'\)/)
  // Obě locale, jinak by managed uživatel viděl klíč místo textu.
  for (const locale of [cs, en]) {
    const messages = JSON.parse(locale)
    assert.ok(messages.supplier_settings?.title)
    assert.ok(messages.nav?.supplier_settings)
  }
})

test('platform navigation and routes use platform permissions only', async () => {
  const [auth, router, layout] = await Promise.all([
    readFile(new URL('stores/auth.ts', root), 'utf8'),
    readFile(new URL('router/index.ts', root), 'utf8'),
    readFile(new URL('components/layout/AppLayout.vue', root), 'utf8'),
  ])

  assert.match(auth, /platform_role \?\? user\.value\?\.role/)
  assert.match(router, /requiresPermission: 'platform_users\.manage'/)
  assert.match(router, /requiresPermission: 'platform_settings\.manage'/)
  assert.match(router, /requiresPermission: 'platform_update\.manage'/)
  assert.match(layout, /auth\.hasPermission\('platform_settings\.manage'\)/)
})

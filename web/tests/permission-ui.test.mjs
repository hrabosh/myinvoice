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

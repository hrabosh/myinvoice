import { api } from './client'

export type SupplierMemberRole = 'supplier_owner' | 'accountant' | 'readonly'

export interface SupplierMember {
  user_id: number
  email: string
  name: string
  role: SupplierMemberRole
  is_active: boolean
}

export const supplierMembersApi = {
  list: () => api.get<SupplierMember[]>('/settings/supplier/members').then(response => response.data),
  updateRole: (userId: number, role: SupplierMemberRole) =>
    api.put<{ members: SupplierMember[]; revoked_sessions: number }>(`/settings/supplier/members/${userId}`, { role })
      .then(response => response.data),
  remove: (userId: number) =>
    api.delete<{ deleted: boolean; revoked_sessions: number }>(`/settings/supplier/members/${userId}`)
      .then(response => response.data),
}

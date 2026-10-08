import type { EstablishmentItem, EstablishmentStaffItem, ClientStaffRoleName } from '@/modules/clients/types/client.types'

export function makeStaffMember(
  guid: string,
  name: string,
  role: ClientStaffRoleName = 'client-manager',
): EstablishmentStaffItem {
  return {
    guid,
    user: { guid: `user-${guid}`, name, first_name: name, last_name: '', email: `${guid}@example.com` },
    role: { guid: `role-${role}`, name: role },
    blocked_at: null,
    created_at: '2026-01-01T00:00:00Z',
  }
}

export function makeEstablishment(overrides: Partial<EstablishmentItem> = {}): EstablishmentItem {
  return {
    guid: 'est-1',
    name: 'Campo La Esperanza',
    renspa: null,
    address: null,
    city: null,
    state: null,
    zip_code: null,
    latitude: null,
    longitude: null,
    created_at: '2026-01-01T00:00:00Z',
    ...overrides,
  }
}

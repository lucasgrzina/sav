const ROLE_LABELS: Record<string, string> = {
  'client-owner':         'Propietario',
  'client-manager':       'Encargado',
  'client-administrative':'Administrativo',
  'vet':                  'Veterinario',
  'vet-administrative':   'Administrativo de Veterinario',
  'vet-assistant':        'Asistente de Veterinario',
}

export function getRoleLabel(name: string): string {
  return ROLE_LABELS[name] ?? name
}

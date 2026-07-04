import { z } from 'zod'

// Schema para creación: name requerido
export const createRoleSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido').max(100, 'Máximo 100 caracteres'),
  permissions: z.array(z.string()).min(1, 'Seleccioná al menos un permiso'),
})

// Schema para edición: name opcional (roles tenant no exponen el campo)
export const updateRoleSchema = z.object({
  name: z.string().min(1).max(100).optional(),
  permissions: z.array(z.string()).min(1, 'Seleccioná al menos un permiso'),
})

export type RoleFormValues = z.infer<typeof createRoleSchema>
export type RoleUpdateFormValues = z.infer<typeof updateRoleSchema>

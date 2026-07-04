import { z } from 'zod'

const contactItemSchema = z.object({
  type:           z.enum(['email', 'phone', 'whatsapp']),
  value:          z.string().min(1, 'El valor del contacto es requerido').max(200),
  label:          z.string().max(100).nullable().optional(),
  is_primary:     z.boolean().default(false),
  use_for_alerts: z.boolean().default(false),
})

/** Schema para el formulario de personal NUEVO (not-found) */
export const vetStaffNewSchema = z.object({
  first_name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(50, 'Máximo 50 caracteres')
    .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras y espacios'),
  last_name: z
    .string()
    .min(1, 'El apellido es requerido')
    .max(50, 'Máximo 50 caracteres')
    .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras y espacios'),
  email:     z.string().email('Email inválido'),
  role_guid: z.string().min(1, 'El rol es requerido'),
  contacts:  z.array(contactItemSchema).optional().default([]),
})

/** Schema para el formulario de personal EXISTENTE (found-linkable) */
export const vetStaffAssignSchema = z.object({
  role_guid: z.string().min(1, 'El rol es requerido'),
  contacts:  z.array(contactItemSchema).optional().default([]),
})

export type VetStaffNewForm    = z.infer<typeof vetStaffNewSchema>
export type VetStaffAssignForm = z.infer<typeof vetStaffAssignSchema>

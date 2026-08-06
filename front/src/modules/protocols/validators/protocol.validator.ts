import { z } from 'zod'

const tenantRoleValues = [
  'vet',
  'vet-assistant',
  'vet-administrative',
  'client-owner',
  'client-manager',
  'client-administrative',
] as const

const timeRegex = /^([01]\d|2[0-3]):[0-5]\d$/

export const protocolTaskAlertSchema = z.object({
  guid: z.string().uuid().optional(),
  offset_days: z.number().int().min(0, 'Debe ser un número mayor o igual a 0').default(0),
  time_of_day: z.enum(['before', 'after']).default('before'),
  time: z.string().regex(timeRegex, 'Hora inválida'),
  roles: z.array(z.enum(tenantRoleValues)).min(1, 'Seleccioná al menos un rol'),
  message: z.string().min(1, 'El mensaje es requerido'),
  require_confirmation: z.boolean().default(false),
})

export const protocolTaskSchema = z.object({
  guid: z.string().uuid().optional(),
  description: z.string().min(1, 'La descripción es requerida'),
  days_offset: z.number().int().min(0, 'Debe ser un número mayor o igual a 0'),
  time_of_day: z.enum(['before', 'after']),
  time: z.string().regex(timeRegex, 'Hora inválida'),
  important: z.boolean().default(false),
  alerts: z.array(protocolTaskAlertSchema).default([]),
})

export const protocolSchema = z.object({
  technique_id: z.string().uuid('Seleccioná una sub-técnica'),
  name: z.string().min(1, 'El nombre es requerido').max(255),
  color: z
    .string()
    .max(20)
    .nullable()
    .optional()
    .transform((v) => v ?? null),
  country_id: z
    .string()
    .uuid()
    .nullable()
    .optional()
    .transform((v) => v ?? null),
  tasks: z.array(protocolTaskSchema).default([]),
})

export type ProtocolFormValues = z.infer<typeof protocolSchema>
export type ProtocolTaskFormValues = z.infer<typeof protocolTaskSchema>
export type ProtocolTaskAlertFormValues = z.infer<typeof protocolTaskAlertSchema>

import { z } from 'zod'

export const clientCreateSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(200, 'Máximo 200 caracteres'),
  country_guid: z
    .string()
    .min(1, 'El país es requerido'),
  document_type_guid: z
    .string()
    .min(1, 'El tipo de documento es requerido'),
  tax_id: z
    .string()
    .min(1, 'El identificador fiscal es requerido')
    .max(30, 'Máximo 30 caracteres'),
  address: z
    .string()
    .max(255, 'Máximo 255 caracteres')
    .nullable()
    .optional(),
  city: z
    .string()
    .max(100, 'Máximo 100 caracteres')
    .nullable()
    .optional(),
  state: z
    .string()
    .max(100, 'Máximo 100 caracteres')
    .nullable()
    .optional(),
  zip_code: z
    .string()
    .max(20, 'Máximo 20 caracteres')
    .nullable()
    .optional(),
})

export const clientUpdateSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(200, 'Máximo 200 caracteres')
    .optional(),
  document_type_guid: z
    .string()
    .min(1, 'El tipo de documento es requerido')
    .optional(),
  tax_id: z
    .string()
    .min(1, 'El identificador fiscal es requerido')
    .max(30, 'Máximo 30 caracteres')
    .optional(),
  address: z
    .string()
    .max(255, 'Máximo 255 caracteres')
    .nullable()
    .optional(),
  city: z
    .string()
    .max(100, 'Máximo 100 caracteres')
    .nullable()
    .optional(),
  state: z
    .string()
    .max(100, 'Máximo 100 caracteres')
    .nullable()
    .optional(),
  zip_code: z
    .string()
    .max(20, 'Máximo 20 caracteres')
    .nullable()
    .optional(),
})

export const establishmentSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(200, 'Máximo 200 caracteres'),
  renspa: z
    .string()
    .max(50, 'Máximo 50 caracteres')
    .nullable()
    .optional(),
  address: z
    .string()
    .max(255, 'Máximo 255 caracteres')
    .nullable()
    .optional(),
  city: z
    .string()
    .max(100, 'Máximo 100 caracteres')
    .nullable()
    .optional(),
  state: z
    .string()
    .max(100, 'Máximo 100 caracteres')
    .nullable()
    .optional(),
  zip_code: z
    .string()
    .max(20, 'Máximo 20 caracteres')
    .nullable()
    .optional(),
  latitude: z
    .number()
    .min(-90, 'La latitud debe ser mayor a -90')
    .max(90, 'La latitud debe ser menor a 90')
    .nullable()
    .optional(),
  longitude: z
    .number()
    .min(-180, 'La longitud debe ser mayor a -180')
    .max(180, 'La longitud debe ser menor a 180')
    .nullable()
    .optional(),
})

export const ownerCreateSchema = z.object({
  email: z
    .string()
    .min(1, 'El email es requerido')
    .email('Formato de email inválido')
    .max(255, 'Máximo 255 caracteres'),
  first_name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(100, 'Máximo 100 caracteres'),
  last_name: z
    .string()
    .min(1, 'El apellido es requerido')
    .max(100, 'Máximo 100 caracteres'),
})

export const contactSchema = z.object({
  type: z
    .string()
    .min(1, 'El tipo de contacto es requerido'),
  value: z
    .string()
    .min(1, 'El valor es requerido')
    .max(255, 'Máximo 255 caracteres'),
  label: z
    .string()
    .max(100, 'Máximo 100 caracteres')
    .nullable()
    .optional(),
  is_primary: z.boolean().optional(),
  use_for_alerts: z.boolean().optional(),
})

export type ClientCreateForm  = z.infer<typeof clientCreateSchema>
export type ClientUpdateForm  = z.infer<typeof clientUpdateSchema>
export type EstablishmentForm = z.infer<typeof establishmentSchema>
export type OwnerCreateForm   = z.infer<typeof ownerCreateSchema>
export type ContactForm       = z.infer<typeof contactSchema>

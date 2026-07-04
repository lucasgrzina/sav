import { z } from 'zod'

const contactFormSchema = z.object({
  type: z.enum(['email', 'phone', 'whatsapp']),
  value: z.string().min(1, 'Requerido').max(200),
  label: z.string().max(100).nullable().optional(),
  is_primary: z.boolean().optional().default(false),
  use_for_alerts: z.boolean().optional().default(false),
})

export const vetCreateSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(150, 'Máximo 150 caracteres'),
  country_guid: z
    .string()
    .min(1, 'El país es requerido'),
  document_type_guid: z
    .string()
    .min(1, 'El tipo de documento es requerido'),
  tax_id: z
    .string()
    .min(1, 'El CUIT/identificador fiscal es requerido')
    .max(30, 'Máximo 30 caracteres'),
  registration_number: z
    .string()
    .max(50, 'Máximo 50 caracteres')
    .nullable()
    .optional(),
  logo_path: z
    .string()
    .max(500, 'Máximo 500 caracteres')
    .nullable()
    .optional(),
  pdf_title: z
    .string()
    .max(150, 'Máximo 150 caracteres')
    .nullable()
    .optional(),
  pdf_subtitle: z
    .string()
    .max(150, 'Máximo 150 caracteres')
    .nullable()
    .optional(),
  contacts: z.array(contactFormSchema).optional(),
})

export const vetUpdateSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(150, 'Máximo 150 caracteres')
    .optional(),
  document_type_guid: z
    .string()
    .min(1, 'El tipo de documento es requerido')
    .optional(),
  tax_id: z
    .string()
    .min(1, 'El CUIT/identificador fiscal es requerido')
    .max(30, 'Máximo 30 caracteres')
    .optional(),
  registration_number: z
    .string()
    .max(50, 'Máximo 50 caracteres')
    .nullable()
    .optional(),
  logo_path: z
    .string()
    .max(500, 'Máximo 500 caracteres')
    .nullable()
    .optional(),
  pdf_title: z
    .string()
    .max(150, 'Máximo 150 caracteres')
    .nullable()
    .optional(),
  pdf_subtitle: z
    .string()
    .max(150, 'Máximo 150 caracteres')
    .nullable()
    .optional(),
  contacts: z.array(contactFormSchema).optional(),
})

export const vetTenantUpdateSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(150, 'Máximo 150 caracteres'),
  pdf_title: z
    .string()
    .max(200, 'Máximo 200 caracteres')
    .nullable()
    .optional(),
  pdf_subtitle: z
    .string()
    .max(200, 'Máximo 200 caracteres')
    .nullable()
    .optional(),
})

export type VetCreateForm = z.infer<typeof vetCreateSchema>
export type VetUpdateForm = z.infer<typeof vetUpdateSchema>
export type VetTenantUpdateForm = z.infer<typeof vetTenantUpdateSchema>

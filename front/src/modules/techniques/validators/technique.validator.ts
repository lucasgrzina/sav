import { z } from 'zod'

const techniqueTypeValues = ['technique', 'vaccine'] as const

export const techniqueChildSchema = z.object({
  guid: z.string().uuid().optional(),
  name: z
    .string()
    .min(1, 'El nombre de la sub-técnica es requerido')
    .max(255, 'El nombre no puede superar 255 caracteres'),
  protocols_name: z
    .string()
    .max(255, 'El label no puede superar 255 caracteres')
    .nullable()
    .optional()
    .transform((val) => val ?? null),
})

export const techniqueSchema = z.object({
  name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(255, 'El nombre no puede superar 255 caracteres'),
  type: z.enum(techniqueTypeValues, {
    errorMap: () => ({ message: 'Seleccioná un tipo' }),
  }),
  target_date_name: z
    .string()
    .max(255, 'El label no puede superar 255 caracteres')
    .nullable()
    .optional()
    .transform((val) => val ?? null),
  protocols_name: z
    .string()
    .max(255, 'El label no puede superar 255 caracteres')
    .nullable()
    .optional()
    .transform((val) => val ?? null),
  children: z.array(techniqueChildSchema).optional().default([]),
})

export type TechniqueFormValues = z.infer<typeof techniqueSchema>

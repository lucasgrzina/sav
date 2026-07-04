import { z } from 'zod'

const sourceValues = ['youtube', 'vimeo'] as const

export const tutorialSchema = z.object({
  title: z
    .string()
    .min(1, 'El título es requerido')
    .max(255, 'El título no puede superar 255 caracteres'),
  description: z
    .string()
    .max(1000, 'La descripción no puede superar 1000 caracteres')
    .nullable()
    .optional()
    .transform((val) => val ?? null),
  source: z.enum(sourceValues, { errorMap: () => ({ message: 'Seleccioná una fuente' }) }),
  code: z
    .string()
    .min(1, 'El código del video es requerido')
    .max(100, 'El código no puede superar 100 caracteres'),
  order: z
    .number({ invalid_type_error: 'El orden debe ser un número' })
    .int('El orden debe ser un número entero')
    .min(0, 'El orden debe ser mayor o igual a 0'),
})

export type TutorialFormValues = z.infer<typeof tutorialSchema>

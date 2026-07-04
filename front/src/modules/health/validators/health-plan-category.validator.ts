import { z } from 'zod'

export const healthPlanCategorySchema = z.object({
  name: z.string().min(1, 'El nombre es requerido').max(255, 'Máximo 255 caracteres'),
  description: z.string().max(500, 'Máximo 500 caracteres').nullable().optional()
    .transform((v) => v ?? null),
})

export type HealthPlanCategoryFormValues = z.infer<typeof healthPlanCategorySchema>

import { z } from 'zod'

const activityAssignmentSchema = z.object({
  health_activity_guid: z.string().uuid(),
  months: z.array(z.number().int().min(1).max(12)).min(1, 'Seleccioná al menos un mes'),
})

export const healthPlanTemplateSchema = z.object({
  name: z.string().min(1, 'El nombre es requerido').max(255, 'Máximo 255 caracteres'),
  health_plan_category_guid: z.string().uuid('Seleccioná una categoría válida'),
  activities: z.array(activityAssignmentSchema).default([]),
})

export type HealthPlanTemplateFormValues = z.infer<typeof healthPlanTemplateSchema>

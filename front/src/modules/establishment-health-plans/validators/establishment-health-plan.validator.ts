import { z } from 'zod'

export const establishmentHealthPlanSchema = z.object({
  establishment_id: z.string().uuid('Seleccioná un establecimiento'),
  health_plan_template_id: z.string().uuid('Seleccioná una plantilla'),
  year: z
    .number({ invalid_type_error: 'El año es requerido' })
    .int('El año debe ser un número entero')
    .min(2020, 'El año no puede ser menor a 2020')
    .max(new Date().getFullYear() + 1, 'El año no puede ser mayor al próximo año calendario'),
})

export type EstablishmentHealthPlanFormValues = z.infer<typeof establishmentHealthPlanSchema>

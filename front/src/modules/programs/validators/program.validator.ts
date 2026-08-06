import { z } from 'zod'

// DEC-07: rp siempre requerido (viaja tanto para animales nuevos como para tags ya resueltos por el picker)
const animalInputSchema = z.object({
  id: z.string().uuid().optional(),
  rp: z.string().min(1, 'El RP no puede estar vacío').max(50),
})

export const programTargetSchema = z.object({
  guid: z.string().uuid().optional(),
  target_date: z.string().min(1, 'La fecha objetivo es requerida'),
  animals: z.array(animalInputSchema).default([]),
})

export const programSchema = z.object({
  client_id: z.string().uuid('Seleccioná un cliente'),
  establishment_id: z.string().uuid('Seleccioná un establecimiento'),
  protocol_id: z.string().uuid('Seleccioná un protocolo'),
  comments: z
    .string()
    .nullable()
    .optional()
    .transform((v) => v ?? null),
  targets: z.array(programTargetSchema).min(1, 'Debe haber al menos un objetivo'),
  manager_profile_ids: z.array(z.string().uuid()).min(1, 'Seleccioná al menos un manager'),
})

export type ProgramFormValues = z.infer<typeof programSchema>
export type ProgramTargetFormValues = z.infer<typeof programTargetSchema>
export type AnimalInputFormValues = z.infer<typeof animalInputSchema>

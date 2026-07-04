import { z } from 'zod'

const priorityValues = ['low', 'medium', 'high'] as const
const categoryValues = ['bug', 'question', 'feature_request', 'other'] as const

function hasRichTextContent(html: string): boolean {
  return html.replace(/<[^>]*>/g, '').trim().length > 0
}

export const createSupportMessageSchema = z.object({
  subject: z
    .string()
    .min(1, 'El asunto es requerido')
    .max(255, 'El asunto no puede superar 255 caracteres'),
  body: z
    .string()
    .refine(hasRichTextContent, 'El mensaje es requerido'),
  priority: z.enum(priorityValues, { errorMap: () => ({ message: 'Seleccioná una prioridad' }) }),
  category: z.enum(categoryValues, { errorMap: () => ({ message: 'Seleccioná una categoría' }) }),
})

export const addReplySchema = z.object({
  body: z
    .string()
    .refine(hasRichTextContent, 'El mensaje es requerido'),
})

export type CreateSupportMessageForm = z.infer<typeof createSupportMessageSchema>
export type AddReplyForm = z.infer<typeof addReplySchema>

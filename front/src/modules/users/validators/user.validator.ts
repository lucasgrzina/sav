import { z } from 'zod'

const passwordSchema = z
  .string()
  .min(8, 'Mínimo 8 caracteres')
  .max(12, 'Máximo 12 caracteres')
  .regex(/[A-Z]/, 'Debe contener al menos una mayúscula')
  .regex(/[0-9]/, 'Debe contener al menos un número')
  .regex(/[!@#$%&]/, 'Debe contener al menos un símbolo (!@#$%&)')

export const userCreateSchema = z
  .object({
    first_name: z
      .string()
      .min(1, 'El nombre es requerido')
      .max(50, 'Máximo 50 caracteres')
      .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
    last_name: z
      .string()
      .min(1, 'El apellido es requerido')
      .max(50, 'Máximo 50 caracteres')
      .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
    email: z.string().email('Email inválido'),
    password: passwordSchema,
    password_confirmation: z.string(),
    role_guids: z.array(z.string()).optional().default([]),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: 'Las contraseñas no coinciden',
    path: ['password_confirmation'],
  })

export const userUpdateSchema = z.object({
  first_name: z
    .string()
    .min(1, 'El nombre es requerido')
    .max(50, 'Máximo 50 caracteres')
    .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
  last_name: z
    .string()
    .min(1, 'El apellido es requerido')
    .max(50, 'Máximo 50 caracteres')
    .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
  email: z.string().email('Email inválido'),
  role_guids: z.array(z.string()).optional().default([]),
})

export const changePasswordSchema = z
  .object({
    password: passwordSchema,
    password_confirmation: z.string(),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: 'Las contraseñas no coinciden',
    path: ['password_confirmation'],
  })

export type UserCreateForm = z.infer<typeof userCreateSchema>
export type UserUpdateForm = z.infer<typeof userUpdateSchema>
export type ChangePasswordForm = z.infer<typeof changePasswordSchema>

const tenantUserProfileSchema = z.object({
  role_guid: z.string().min(1, 'Seleccioná un rol'),
  vet_guid: z.string().optional(),
  client_guid: z.string().optional(),
})

export const tenantUserCreateSchema = z
  .object({
    first_name: z
      .string()
      .min(1, 'El nombre es requerido')
      .max(50, 'Máximo 50 caracteres')
      .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
    last_name: z
      .string()
      .min(1, 'El apellido es requerido')
      .max(50, 'Máximo 50 caracteres')
      .regex(/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]+$/, 'Solo letras'),
    email: z.string().email('Email inválido'),
    password: passwordSchema,
    password_confirmation: z.string(),
    profiles: z
      .array(tenantUserProfileSchema)
      .min(1, 'Debe agregar al menos un perfil de acceso'),
  })
  .refine((d) => d.password === d.password_confirmation, {
    message: 'Las contraseñas no coinciden',
    path: ['password_confirmation'],
  })

export type TenantUserCreateForm = z.infer<typeof tenantUserCreateSchema>

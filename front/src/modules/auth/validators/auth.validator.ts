import { z } from 'zod';

const passwordSchema = z
    .string()
    .min(8, 'Mínimo 8 caracteres')
    .max(12, 'Máximo 12 caracteres')
    .regex(/[A-Z]/, 'Debe contener al menos una mayúscula')
    .regex(/[0-9]/, 'Debe contener al menos un número')
    .regex(/[!@#$%&]/, 'Debe contener al menos un símbolo (!@#$%&)');

export const loginSchema = z.object({
    email: z.string().email('Email inválido'),
    password: z.string().min(1, 'La contraseña es requerida'),
    remember: z.boolean().optional(),
});

export const registerSchema = z
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
    })
    .refine((d) => d.password === d.password_confirmation, {
        message: 'Las contraseñas no coinciden',
        path: ['password_confirmation'],
    });

export const verifyCodeSchema = z.object({
    code: z.string().length(6, 'El código debe tener 6 dígitos'),
});

export const forgotPasswordEmailSchema = z.object({
    email: z.string().email('Email inválido'),
});

export const resetPasswordSchema = z
    .object({
        password: passwordSchema,
        password_confirmation: z.string(),
    })
    .refine((d) => d.password === d.password_confirmation, {
        message: 'Las contraseñas no coinciden',
        path: ['password_confirmation'],
    });

export type LoginForm = z.infer<typeof loginSchema>;
export type RegisterForm = z.infer<typeof registerSchema>;
export type VerifyCodeForm = z.infer<typeof verifyCodeSchema>;
export type ForgotPasswordEmailForm = z.infer<typeof forgotPasswordEmailSchema>;
export type ResetPasswordForm = z.infer<typeof resetPasswordSchema>;

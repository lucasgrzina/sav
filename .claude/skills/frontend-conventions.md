# Convenciones de arquitectura frontend SAV (Vue 3 + TypeScript)

Estas convenciones son no negociables para todo código frontend en SAV.

## Feature Module Pattern

Cada módulo vive en `front/src/modules/{nombre}/` con 8 sub-carpetas obligatorias:
`api/`, `components/`, `composables/`, `pages/`, `router/`, `stores/`, `types/`, `validators/`.

## TypeScript estricto

- Nunca usar `any`. Si el tipo es desconocido, usar `unknown` con type guard.
- Props con `defineProps<{}>()`, emits con `defineEmits<{}>()`.
- Todos los tipos en `types/`.

## Vue Query para server state

- `useQuery` para lecturas, `useMutation` para escrituras.
- El server state NO va en Pinia.
- Toda `useMutation` debe llamar `invalidateQueries` con la key correspondiente al completarse.

## Pinia solo para UI state

- Modales abiertos, filtros activos, estado de drawers.
- Nunca datos de servidor en Pinia.
- Stores en `stores/{nombre}-ui.store.ts`.

## Composables

- Prefijo `use` obligatorio.
- Un composable por operación: `useCreate{Nombre}`, `useUpdate{Nombre}`, `useDelete{Nombre}`, `use{Nombre}s`.
- Sin lógica de negocio en componentes — va en composables.

## Componentes

- `<script setup lang="ts">` siempre.
- Usar átomos existentes en `front/src/components/atoms/` antes de crear nuevos:
  `BaseInput`, `BaseSelect`, `BasePasswordInput`, `BaseDateRangePicker`, `BaseModal`, `BaseDrawer`, `BaseConfirmDialog`, `BaseDataTable`, `BaseTableActions`.
- Si el átomo que necesitás no existe, crealo en `atoms/` antes de usarlo.

## PermissionGuard

- Toda acción de escritura (crear, editar, eliminar) envuelta en `<PermissionGuard :permission="'modulo.accion'">`.
- Importar desde `@/components/shared/PermissionGuard.vue`.

## Validación

- Zod + Vee-Validate: schemas en `validators/`, usados con `useForm`.
- Mensajes de error en español.
- Exportar tipo inferido: `export type FormValues = z.infer<typeof schema>`.

## API Layer

- Funciones en `api/{nombre}.api.ts`.
- Retornan datos directamente — el interceptor ya desenvuelve `{ success, data }`.
- GUID como identificador en todas las llamadas.

## i18n

- Nunca strings hardcodeados en templates — siempre `$t('clave')`.
- En `<script setup>`: `const { t } = useI18n()`.
- Claves en `front/src/i18n/locales/es/`.
- Idioma del sistema: es-AR.

## Router

- Lazy loading obligatorio: `component: () => import(...)`.
- `authGuard` en `beforeEnter` de toda ruta protegida.
- Rutas del módulo en `router/{nombre}.routes.ts`, registradas en `front/src/router/index.ts`.

## Estilos

- Tailwind para layout/spacing/colores. Sin CSS inline salvo casos puntuales.
- Ant Design Vue 4 para componentes de negocio.

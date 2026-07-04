# Plan de Refactorización Frontend — VetAlert

**Guía base:** `docs/refactorizacion.md`
**Stack objetivo:** Vue 3 + TS Strict + Pinia + Vue Query + Vee-Validate + Zod + Axios + Tailwind + Atomic Design + Feature Modules
**Fecha:** 2026-05-14

---

## Diagnóstico: Estado Actual vs. Objetivo

### Brechas identificadas

| Área | Estado actual | Objetivo |
|---|---|---|
| Estructura | `views/` plana + `store/` global | Feature Modules desacoplados |
| API layer | Axios directo en stores | `/api` + mappers por módulo |
| Stores | Pinia con fetching + UI state mezclados | Pinia solo para UI/global; Vue Query para server state |
| Componentes | Átomos parciales, vistas monolíticas | Atomic Design completo |
| Lazy loading | Sin lazy loading de rutas | Dynamic imports por módulo |
| Tipos | Solo en `types/api/` (parciales) | Tipado estricto por módulo |
| Validadores | En `validators/` global | Por módulo |
| Composables | 5 composables básicos | Composables por entidad + core |
| Skeletons | Ausentes | Obligatorios en todas las vistas |
| Error states | Parciales | Mandatory por vista |
| Mappers | Ausentes | DTO mapper por módulo |

### Componentes monolíticos detectados (> 200 líneas)

- `UsersView.vue` — CRUD completo embebido en una vista (tabla + 4 modales + filtros)
- `RoleFormView.vue` — Formulario + lógica de permisos en una sola vista
- `DashboardLayout.vue` — Layout + sidebar + header + user menu + theme toggle

---

## Estructura Objetivo

```
front/src/
├── core/
│   ├── api/
│   │   ├── http.ts                     (EXISTENTE — mover desde src/api/)
│   │   └── interceptors/
│   │       ├── auth.interceptor.ts     (EXTRAER de http.ts)
│   │       └── error.interceptor.ts    (EXTRAER de http.ts)
│   ├── composables/
│   │   ├── useDebounce.ts              (NUEVO)
│   │   ├── usePagination.ts            (NUEVO)
│   │   ├── useModal.ts                 (NUEVO)
│   │   ├── useDrawer.ts                (NUEVO)
│   │   ├── useConfirm.ts               (MOVER desde composables/)
│   │   ├── usePermissions.ts           (MOVER desde composables/)
│   │   ├── useResponsive.ts            (NUEVO)
│   │   └── useNotification.ts          (MOVER desde composables/)
│   ├── constants/
│   │   ├── routes.ts                   (EXTRAER del router)
│   │   ├── permissions.ts              (NUEVO)
│   │   └── app.ts                      (MOVER desde config/constants.ts)
│   ├── plugins/
│   │   ├── antd.ts                     (EXTRAER de main.ts)
│   │   ├── vue-query.ts                (NUEVO — instalar)
│   │   ├── pinia.ts                    (EXTRAER de main.ts)
│   │   ├── vee-validate.ts             (NUEVO — centralizar)
│   │   └── i18n.ts                     (EXTRAER de main.ts)
│   ├── services/
│   │   ├── auth.service.ts             (MOVER desde services/)
│   │   ├── storage.service.ts          (NUEVO)
│   │   └── notification.service.ts     (NUEVO)
│   ├── types/
│   │   ├── api.types.ts                (MOVER desde types/api/)
│   │   ├── pagination.types.ts         (NUEVO)
│   │   └── ui.types.ts                 (NUEVO)
│   └── utils/
│       ├── date.ts                     (NUEVO)
│       ├── currency.ts                 (NUEVO)
│       └── string.ts                   (NUEVO)
│
├── components/
│   ├── atoms/
│   │   ├── buttons/
│   │   │   ├── BaseButton.vue          (EXISTENTE — revisar)
│   │   │   ├── BaseIconButton.vue      (NUEVO)
│   │   │   └── BaseFloatingButton.vue  (NUEVO)
│   │   ├── inputs/
│   │   │   ├── BaseInput.vue           (EXISTENTE — revisar)
│   │   │   ├── BaseTextarea.vue        (NUEVO)
│   │   │   ├── BasePasswordInput.vue   (NUEVO)
│   │   │   └── BaseSearchInput.vue     (NUEVO)
│   │   ├── selects/
│   │   │   ├── BaseSelect.vue          (NUEVO)
│   │   │   └── BaseMultiSelect.vue     (NUEVO)
│   │   ├── pickers/
│   │   │   ├── BaseDatePicker.vue      (NUEVO)
│   │   │   └── BaseDateRangePicker.vue (NUEVO)
│   │   ├── feedback/
│   │   │   ├── BaseSpinner.vue         (NUEVO)
│   │   │   ├── BaseSkeleton.vue        (NUEVO — obligatorio)
│   │   │   ├── BaseAlert.vue           (NUEVO)
│   │   │   ├── BaseEmptyState.vue      (MOVER desde shared/)
│   │   │   └── BaseErrorState.vue      (NUEVO)
│   │   ├── display/
│   │   │   ├── BaseAvatar.vue          (NUEVO)
│   │   │   ├── BaseBadge.vue           (NUEVO)
│   │   │   └── BaseTag.vue             (NUEVO)
│   │   ├── overlays/
│   │   │   ├── BaseModal.vue           (NUEVO — wrapper genérico)
│   │   │   ├── BaseDrawer.vue          (NUEVO)
│   │   │   └── BaseConfirmDialog.vue   (MOVER desde shared/ConfirmDialog.vue)
│   │   ├── navigation/
│   │   │   ├── BaseBreadcrumb.vue      (NUEVO)
│   │   │   └── BasePagination.vue      (MOVER desde shared/TablePagination.vue)
│   │   ├── tables/
│   │   │   ├── BaseDataTable.vue       (EXISTENTE — revisar)
│   │   │   ├── BaseTableActions.vue    (EXISTENTE — revisar)
│   │   │   └── BaseTableEmpty.vue      (EXISTENTE — revisar)
│   │   └── cards/
│   │       ├── BaseCard.vue            (NUEVO)
│   │       └── BaseStatsCard.vue       (NUEVO — extraer de MetricCard)
│   │
│   ├── molecules/
│   │   ├── forms/
│   │   │   ├── FormFieldWrapper.vue    (EXISTENTE — revisar)
│   │   │   ├── FormInput.vue           (NUEVO)
│   │   │   ├── FormSelect.vue          (NUEVO)
│   │   │   ├── FormDateRangePicker.vue (NUEVO)
│   │   │   ├── FormCheckbox.vue        (NUEVO)
│   │   │   ├── FormSwitch.vue          (NUEVO)
│   │   │   └── FormRadioGroup.vue      (NUEVO)
│   │   ├── filters/
│   │   │   ├── SearchFilter.vue        (MOVER desde filters/)
│   │   │   ├── StatusFilter.vue        (NUEVO)
│   │   │   └── DateRangeFilter.vue     (NUEVO)
│   │   └── tables/
│   │       ├── TableToolbar.vue        (NUEVO — extraer de FilterBar)
│   │       └── TablePagination.vue     (REFACTOR de shared/)
│   │
│   ├── layouts/
│   │   ├── DashboardLayout.vue         (REFACTOR — descomponer)
│   │   ├── AuthLayout.vue              (REFACTOR — descomponer)
│   │   └── partials/
│   │       ├── AppSidebar.vue          (EXTRAER de DashboardLayout)
│   │       ├── AppHeader.vue           (MOVER desde layouts/partials/)
│   │       ├── AppMenu.vue             (EXTRAER de DashboardLayout)
│   │       └── AppUserMenu.vue         (EXTRAER de DashboardLayout)
│   │
│   └── shared/
│       ├── PermissionGuard.vue         (NUEVO)
│       ├── ErrorBoundary.vue           (NUEVO)
│       └── AsyncContent.vue            (NUEVO)
│
├── modules/
│   ├── auth/
│   │   ├── api/
│   │   │   ├── auth.api.ts             (MOVER desde src/api/auth.ts)
│   │   │   └── auth.mapper.ts          (NUEVO)
│   │   ├── composables/
│   │   │   ├── useLogin.ts             (EXTRAER de useAuth)
│   │   │   ├── useForgotPassword.ts    (NUEVO)
│   │   │   └── useRegister.ts          (NUEVO)
│   │   ├── components/
│   │   │   ├── AuthBrandPanel.vue      (MOVER desde components/auth/)
│   │   │   ├── AuthFormField.vue       (MOVER — revisar si se reemplaza por FormInput)
│   │   │   └── AuthServerError.vue     (MOVER desde components/auth/)
│   │   ├── pages/
│   │   │   ├── LoginPage.vue           (RENOMBRAR desde LoginView)
│   │   │   ├── RegisterPage.vue        (RENOMBRAR)
│   │   │   ├── ForgotPasswordPage.vue  (RENOMBRAR)
│   │   │   ├── ResetPasswordPage.vue   (RENOMBRAR)
│   │   │   └── VerifyAccountPage.vue   (RENOMBRAR)
│   │   ├── router/
│   │   │   └── auth.routes.ts          (EXTRAER del router global)
│   │   ├── stores/
│   │   │   └── auth.store.ts           (MOVER desde store/auth.ts)
│   │   ├── validators/
│   │   │   └── auth.validator.ts       (MOVER desde validators/)
│   │   └── types/
│   │       └── auth.types.ts           (MOVER desde types/api/)
│   │
│   ├── users/
│   │   ├── api/
│   │   │   ├── users.api.ts            (MOVER desde src/api/users.ts)
│   │   │   └── users.mapper.ts         (NUEVO — extraer transformaciones)
│   │   ├── composables/
│   │   │   ├── useUsers.ts             (NUEVO — Vue Query list)
│   │   │   ├── useUser.ts              (NUEVO — Vue Query single)
│   │   │   ├── useCreateUser.ts        (NUEVO — mutation)
│   │   │   ├── useUpdateUser.ts        (NUEVO — mutation)
│   │   │   ├── useDeleteUser.ts        (NUEVO — mutation)
│   │   │   ├── useUserFilters.ts       (NUEVO — extraer filtros de UsersView)
│   │   │   ├── useChangePassword.ts    (NUEVO — mutation)
│   │   │   └── useToggleLock.ts        (NUEVO — mutation)
│   │   ├── components/
│   │   │   ├── UsersTable.vue          (EXTRAER de UsersView)
│   │   │   ├── UserFilters.vue         (EXTRAER de UsersView)
│   │   │   ├── UserStatusBadge.vue     (NUEVO)
│   │   │   ├── forms/
│   │   │   │   ├── UserForm.vue        (EXTRAER de UsersView)
│   │   │   │   └── ChangePasswordForm.vue (EXTRAER de UsersView)
│   │   │   └── modals/
│   │   │       ├── CreateUserModal.vue (EXTRAER de UsersView)
│   │   │       ├── EditUserModal.vue   (EXTRAER de UsersView)
│   │   │       ├── DeleteUserModal.vue (EXTRAER de UsersView)
│   │   │       └── ChangePasswordModal.vue (EXTRAER de UsersView)
│   │   ├── pages/
│   │   │   └── UsersPage.vue           (REFACTOR de UsersView — solo orquesta)
│   │   ├── router/
│   │   │   └── users.routes.ts         (EXTRAER del router global)
│   │   ├── stores/
│   │   │   └── users.store.ts          (REDUCIR — solo UI state; data → Vue Query)
│   │   ├── validators/
│   │   │   ├── user.validator.ts       (MOVER desde validators/)
│   │   │   └── change-password.validator.ts (EXTRAER)
│   │   ├── types/
│   │   │   ├── user.types.ts           (NUEVO)
│   │   │   └── user.enums.ts           (NUEVO)
│   │   └── constants/
│   │       └── users.constants.ts      (NUEVO)
│   │
│   ├── roles/
│   │   ├── api/
│   │   │   ├── roles.api.ts            (MOVER desde src/api/roles.ts)
│   │   │   └── roles.mapper.ts         (NUEVO)
│   │   ├── composables/
│   │   │   ├── useRoles.ts             (NUEVO — Vue Query)
│   │   │   ├── useRole.ts              (NUEVO — Vue Query single)
│   │   │   ├── useCreateRole.ts        (NUEVO — mutation)
│   │   │   ├── useUpdateRole.ts        (NUEVO — mutation)
│   │   │   └── useDeleteRole.ts        (NUEVO — mutation)
│   │   ├── components/
│   │   │   ├── RolesTable.vue          (EXTRAER de RolesListView)
│   │   │   ├── RoleForm.vue            (EXTRAER de RoleFormView)
│   │   │   └── RolePermissionsSelector.vue (EXTRAER — lógica de permisos)
│   │   ├── pages/
│   │   │   ├── RolesPage.vue           (REFACTOR de RolesListView)
│   │   │   └── RoleFormPage.vue        (REFACTOR de RoleFormView)
│   │   ├── router/
│   │   │   └── roles.routes.ts         (EXTRAER del router global)
│   │   ├── stores/
│   │   │   └── roles.store.ts          (REDUCIR — solo UI state)
│   │   ├── validators/
│   │   │   └── role.validator.ts       (NUEVO)
│   │   └── types/
│   │       └── role.types.ts           (MOVER desde types/api/roles.types.ts)
│   │
│   ├── permissions/
│   │   ├── api/
│   │   │   └── permissions.api.ts      (MOVER desde src/api/permissions.ts)
│   │   ├── composables/
│   │   │   └── usePermissions.ts       (NUEVO — Vue Query)
│   │   ├── stores/
│   │   │   └── permissions.store.ts    (MOVER desde store/permissions.ts)
│   │   └── types/
│   │       └── permission.types.ts     (NUEVO)
│   │
│   └── dashboard/
│       ├── api/
│       │   └── dashboard.api.ts        (NUEVO — reemplazar mock)
│       ├── composables/
│       │   ├── useMetrics.ts           (NUEVO — Vue Query)
│       │   ├── useAlerts.ts            (NUEVO — Vue Query)
│       │   └── useActivity.ts          (NUEVO — Vue Query)
│       ├── components/
│       │   ├── MetricCard.vue          (MOVER desde components/dashboard/)
│       │   ├── AlertsTable.vue         (MOVER desde components/dashboard/)
│       │   ├── ActivityFeed.vue        (MOVER desde components/dashboard/)
│       │   └── TrendChart.vue          (MOVER desde components/dashboard/)
│       ├── pages/
│       │   └── DashboardPage.vue       (REFACTOR de DashboardView)
│       ├── router/
│       │   └── dashboard.routes.ts     (EXTRAER)
│       └── stores/
│           └── dashboard.store.ts      (REFACTOR — eliminar mock data)
│
├── router/
│   ├── index.ts                        (REFACTOR — importa módulos)
│   ├── guards/
│   │   ├── auth.guard.ts               (EXTRAER de guards.ts)
│   │   └── guest.guard.ts              (EXTRAER de guards.ts)
│   └── routes.ts                       (REFACTOR — lazy loading)
│
├── stores/
│   ├── auth.store.ts                   (MANTENER para global auth state)
│   ├── app.store.ts                    (NUEVO — estado global app)
│   └── ui.store.ts                     (NUEVO — modales, drawers, loaders globales)
│
└── styles/
    ├── main.css                        (REFACTOR — solo imports + resets)
    ├── variables.css                   (EXTRAER de main.css)
    ├── animations.css                  (EXTRAER de main.css)
    └── transitions.css                 (EXTRAER de main.css)
```

---

## Fases de Implementación

### Fase 0 — Instalación de dependencias faltantes

Instalar:
- `@tanstack/vue-query` (Vue Query — server state)
- `@vueuse/core` (utilities reactivas)

Configurar plugins en `core/plugins/`:
- `vue-query.ts` — QueryClient + VueQueryPlugin
- `pinia.ts` — extraer de main.ts
- `antd.ts` — extraer de main.ts
- `i18n.ts` — extraer de main.ts
- `vee-validate.ts` — centralizar configuración global

Actualizar `main.ts` para importar desde plugins.

---

### Fase 1 — Core Layer

**Objetivo:** Establecer la base compartida sin tocar features existentes.

**1.1 API Core**
- Mover `src/api/http.ts` → `src/core/api/http.ts`
- Extraer interceptores:
  - `core/api/interceptors/auth.interceptor.ts` — inyección de Bearer token
  - `core/api/interceptors/error.interceptor.ts` — manejo 401/422/500, reset auth

**1.2 Tipos globales**
- `core/types/api.types.ts` — wrapper genérico `ApiResponse<T>`, `ApiError`
- `core/types/pagination.types.ts` — `PaginatedResponse<T>`, `PaginationParams`
- `core/types/ui.types.ts` — `SelectOption`, `TableColumn<T>`, `ModalConfig`

**1.3 Composables core**
- `useDebounce.ts` — debounce reactivo para búsquedas
- `usePagination.ts` — manejo estándar (page, perPage, total)
- `useModal.ts` — open/close/payload pattern
- `useDrawer.ts` — ídem para drawers
- `useResponsive.ts` — breakpoints reactivos con VueUse
- Mover `useConfirm.ts`, `usePermissions.ts`, `useNotification.ts` → `core/composables/`

**1.4 Constants**
- `core/constants/routes.ts` — constantes de rutas nombradas
- `core/constants/permissions.ts` — claves de permisos del sistema
- `core/constants/app.ts` — mover desde `config/constants.ts`

**1.5 Utils**
- `core/utils/date.ts` — formatDate, formatRelative, parseDate
- `core/utils/string.ts` — capitalize, truncate, slugify
- `core/utils/currency.ts` — formatCurrency, parseCurrency

---

### Fase 2 — Componentes Base (Atomic Design)

**Objetivo:** Biblioteca de componentes reutilizables completa antes de migrar módulos.

**2.1 Atoms — Feedback (prioritarios)**
- `BaseSkeleton.vue` — wrappea `a-skeleton`, props: lines, avatar, active, custom
- `BaseSpinner.vue` — wrappea `a-spin` con size y tip
- `BaseEmptyState.vue` — mejorar EmptyState.vue (icon, title, description, action slot)
- `BaseErrorState.vue` — estado de error con mensaje y botón retry (emit)
- `BaseAlert.vue` — wrappea `a-alert` con type, message, description, closable

**2.2 Atoms — Overlays**
- `BaseModal.vue` — wrapper genérico de `a-modal` con slots: default, header, footer
- `BaseDrawer.vue` — wrapper genérico de `a-drawer` con slots: default, footer
- `BaseConfirmDialog.vue` — mover desde `shared/ConfirmDialog.vue`, tipado

**2.3 Atoms — Inputs**
- Revisar `BaseInput.vue` (agregar: size, prefix, suffix, status props)
- `BasePasswordInput.vue` — input password con toggle show/hide
- `BaseSearchInput.vue` — input search con debounce integrado (emit:search)
- `BaseTextarea.vue` — wrappea `a-textarea` con autosize
- `BaseSelect.vue` — wrappea `a-select`, props: options, loading, allowClear
- `BaseMultiSelect.vue` — mode="multiple" con selectAll
- `BaseDateRangePicker.vue` — wrappea `a-range-picker`

**2.4 Atoms — Display**
- `BaseBadge.vue` — wrappea `a-badge` con status semántico
- `BaseTag.vue` — wrappea `a-tag` con colores por tipo
- `BaseAvatar.vue` — wrappea `a-avatar` con fallback a iniciales

**2.5 Atoms — Navigation**
- `BasePagination.vue` — refactor de `TablePagination.vue`; props: total, page, perPage; emit: change

**2.6 Atoms — Cards**
- `BaseCard.vue` — wrappea `a-card` con slots: header, default, footer
- `BaseStatsCard.vue` — extraer de MetricCard.vue

**2.7 Molecules — Forms**
- `FormInput.vue` — `FormFieldWrapper` + `BaseInput` + error display vee-validate
- `FormSelect.vue` — ídem con BaseSelect
- `FormDateRangePicker.vue` — ídem con BaseDateRangePicker
- `FormCheckbox.vue` — wrappea `a-checkbox` con validación
- `FormSwitch.vue` — wrappea `a-switch` con label
- `FormRadioGroup.vue` — wrappea `a-radio-group`

**2.8 Molecules — Filters**
- `SearchFilter.vue` — mover desde `filters/`, integrar debounce
- `StatusFilter.vue` — select de estado genérico (options via prop)
- `DateRangeFilter.vue` — rango de fechas con shortcuts (hoy, semana, mes)

**2.9 Molecules — Tables**
- `TableToolbar.vue` — barra superior de tabla (search + filters + actions slot)
- `TablePagination.vue` — refactor del de shared/

**2.10 Layouts — Descomposición de DashboardLayout**
- `AppSidebar.vue` — sidebar colapsable con logo y menú
- `AppMenu.vue` — menú de navegación con items configurables
- `AppUserMenu.vue` — dropdown de usuario (perfil, tema, logout)
- `DashboardLayout.vue` — orquesta los anteriores (< 100 líneas)
- `AuthLayout.vue` — revisar y documentar slots

**2.11 Shared**
- `PermissionGuard.vue` — muestra/oculta contenido según permiso (`v-can`)
- `ErrorBoundary.vue` — captura errores de hijos, muestra BaseErrorState
- `AsyncContent.vue` — wrapper que maneja loading/error/empty automáticamente

---

### Fase 3 — Módulo Auth

**Objetivo:** Migrar autenticación a módulo desacoplado.

**3.1 Tipos**
- Mover `types/api/auth.types.ts` → `modules/auth/types/auth.types.ts`
- Agregar: `LoginPayload`, `RegisterPayload`, `TokenResponse`

**3.2 API + Mapper**
- Mover `src/api/auth.ts` → `modules/auth/api/auth.api.ts`
- Crear `modules/auth/api/auth.mapper.ts`:
  - `toUser(raw): User` — mapea respuesta a tipo interno
  - `toLoginPayload(form): LoginPayload`

**3.3 Store**
- Mover `store/auth.ts` → `modules/auth/stores/auth.store.ts`
- Dejar re-export en `stores/auth.store.ts` durante transición

**3.4 Composables**
- `useLogin.ts` — maneja form state, llamada API, redirección post-login
- `useRegister.ts` — maneja registro y verificación
- `useForgotPassword.ts` — flujo completo olvidar contraseña (email → código → reset)

**3.5 Componentes**
- Mover `components/auth/` → `modules/auth/components/`
- Evaluar si `AuthFormField.vue` se puede reemplazar por `FormInput.vue` molecular

**3.6 Páginas**
- Mover y renombrar `views/auth/*.vue` → `modules/auth/pages/*.vue`
- Convención: `View` → `Page`
- Agregar skeleton loading en cada página

**3.7 Validators**
- Mover `validators/auth.validators.ts` → `modules/auth/validators/auth.validator.ts`

**3.8 Router**
- Crear `modules/auth/router/auth.routes.ts`
- Lazy loading: `component: () => import('../pages/LoginPage.vue')`
- Aplicar `guest.guard` en todas las rutas de auth

---

### Fase 4 — Módulo Users

**Objetivo:** Descomponer `UsersView.vue` (monolítico) en módulo completo.

**4.1 Tipos**
- `modules/users/types/user.types.ts`:
  - `User`, `UserItem`, `UserCreatePayload`, `UserUpdatePayload`
  - `ChangePasswordPayload`, `UserFilters`
- `modules/users/types/user.enums.ts`:
  - `UserStatus` (active, blocked, pending)

**4.2 Constants**
- `modules/users/constants/users.constants.ts`:
  - `USER_STATUS_LABELS`, `USER_STATUS_COLORS`
  - `USERS_PER_PAGE`

**4.3 API + Mapper**
- Mover `src/api/users.ts` → `modules/users/api/users.api.ts`
- Crear `modules/users/api/users.mapper.ts`:
  - `toUserItem(raw): UserItem`
  - `toCreatePayload(form): UserCreatePayload`
  - `toUpdatePayload(form): UserUpdatePayload`

**4.4 Composables (Vue Query)**
- `useUsers.ts` — `useQuery(['users', filters])` lista paginada + filtros reactivos
- `useUser.ts` — `useQuery(['user', guid])` detalle por guid
- `useCreateUser.ts` — `useMutation` + `invalidateQueries(['users'])`
- `useUpdateUser.ts` — `useMutation` + `invalidateQueries`
- `useDeleteUser.ts` — `useMutation` + confirm + `invalidateQueries`
- `useUserFilters.ts` — estado reactivo de filtros con debounce
- `useChangePassword.ts` — `useMutation` solo
- `useToggleLock.ts` — `useMutation` con optimistic update

**4.5 Store**
- `users.store.ts` — SOLO UI state:
  - `selectedUser: UserItem | null`
  - `activeModal: 'create' | 'edit' | 'delete' | 'changePassword' | null`

**4.6 Componentes (extraídos de UsersView)**
- `UsersTable.vue` — recibe `users[]`, `loading`, columns config; emite: edit, delete, changePassword, toggleLock
- `UserFilters.vue` — recibe filtros actuales; emite: update:filters
- `UserStatusBadge.vue` — BaseTag con color según UserStatus
- `forms/UserForm.vue` — vee-validate + Zod; modo create/edit via prop
- `forms/ChangePasswordForm.vue` — formulario cambio de contraseña
- `modals/CreateUserModal.vue` — BaseModal + UserForm
- `modals/EditUserModal.vue` — BaseModal + UserForm pre-poblado
- `modals/DeleteUserModal.vue` — BaseConfirmDialog tipado para usuario
- `modals/ChangePasswordModal.vue` — BaseModal + ChangePasswordForm

**4.7 Página**
- `UsersPage.vue` — orquesta componentes, sin lógica de negocio (< 100 líneas)

**4.8 Router + Validators**
- `users.routes.ts` con lazy loading y `auth.guard`
- `user.validator.ts` — createSchema, updateSchema
- `change-password.validator.ts`

---

### Fase 5 — Módulo Roles

**Objetivo:** Descomponer `RoleFormView.vue` y `RolesListView.vue`.

**5.1 Tipos**
- Mover `types/api/roles.types.ts` → `modules/roles/types/role.types.ts`
- Agregar: `RoleCreatePayload`, `RoleUpdatePayload`, `RoleFilters`

**5.2 API + Mapper**
- Mover `src/api/roles.ts` → `modules/roles/api/roles.api.ts`
- Crear `modules/roles/api/roles.mapper.ts`:
  - `toRoleItem(raw): RoleItem`
  - `toCreatePayload(form): RoleCreatePayload`

**5.3 Composables (Vue Query)**
- `useRoles.ts` — lista con filtros opcionales
- `useRole.ts` — detalle por guid (para edición)
- `useCreateRole.ts`, `useUpdateRole.ts`, `useDeleteRole.ts`

**5.4 Componentes**
- `RolesTable.vue` — tabla extraída de `RolesListView`
- `RoleForm.vue` — formulario extraído de `RoleFormView`
- `RolePermissionsSelector.vue` — lógica de selección de permisos agrupados por módulo (hoy embebida en RoleFormView)

**5.5 Store**
- `roles.store.ts` — solo UI state: modal activo, rol seleccionado

**5.6 Router + Validators**
- `roles.routes.ts` con lazy loading
- `role.validator.ts` — nameSchema, permissionsSchema

---

### Fase 6 — Módulo Permissions

**Objetivo:** Encapsular gestión de permisos como módulo independiente.

- Mover `src/api/permissions.ts` → `modules/permissions/api/permissions.api.ts`
- Crear `modules/permissions/composables/usePermissions.ts` con Vue Query
  - Cache largo (permissions cambian raramente): `staleTime: 1000 * 60 * 30`
- Refactor `store/permissions.ts` → `modules/permissions/stores/permissions.store.ts`
- Crear `modules/permissions/types/permission.types.ts`

---

### Fase 7 — Módulo Dashboard

**Objetivo:** Reemplazar mock data por API real; agregar skeleton loading.

- Crear `modules/dashboard/api/dashboard.api.ts` con endpoints reales
  - Si no existen aún, mantener mock como fallback comentado
- Crear composables Vue Query: `useMetrics`, `useAlerts`, `useActivity`
  - `staleTime` corto para datos en tiempo real
- Mover componentes `components/dashboard/` → `modules/dashboard/components/`
- Refactor `DashboardPage.vue`:
  - Skeleton loading mientras carga (`BaseSkeleton` por sección)
  - Error state si falla la carga
- `dashboard.store.ts` — eliminar mock data, mantener solo UI state

---

### Fase 8 — Router Global

**Objetivo:** Router limpio que importa módulos con lazy loading.

```typescript
// router/index.ts — resultado final
import { authRoutes } from '@/modules/auth/router/auth.routes'
import { usersRoutes } from '@/modules/users/router/users.routes'
import { rolesRoutes } from '@/modules/roles/router/roles.routes'
import { dashboardRoutes } from '@/modules/dashboard/router/dashboard.routes'

const routes = [
  ...authRoutes,
  {
    path: '/',
    component: () => import('@/components/layouts/DashboardLayout.vue'),
    children: [
      ...dashboardRoutes,
      ...usersRoutes,
      ...rolesRoutes,
    ]
  },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' }
]
```

Guards separados:
- `guards/auth.guard.ts` — requiere autenticación, redirige a /login
- `guards/guest.guard.ts` — solo para no autenticados, redirige a /dashboard

---

### Fase 9 — Estilos

**Objetivo:** CSS mantenible y organizado.

Extraer de `assets/css/main.css`:
- `styles/variables.css` — todos los CSS custom properties (colores, tipografía, spacing, paletas)
- `styles/animations.css` — keyframes (ECG animation, fade, etc.)
- `styles/transitions.css` — transiciones de Vue (`v-enter-*`, `v-leave-*`)
- `styles/main.css` — solo `@import` de los anteriores + resets base

---

### Fase 10 — Limpieza

**Objetivo:** Eliminar carpetas y archivos legacy.

Eliminar una vez confirmado que nada los importa:
- `src/views/` — reemplazado por `modules/*/pages/`
- `src/store/` — reemplazado por `modules/*/stores/` y `stores/`
- `src/api/` — reemplazado por `modules/*/api/` y `core/api/`
- `src/components/auth/` — movido a `modules/auth/components/`
- `src/components/dashboard/` — movido a `modules/dashboard/components/`
- `src/validators/` — movido a módulos
- `src/types/` — movido a módulos y `core/types/`
- `src/config/` — movido a `core/constants/`
- `src/services/` — movido a `core/services/`

---

## Orden de Ejecución para el Agente

```
1.  Fase 0  → Dependencias + plugins
2.  Fase 1  → Core layer (sin tocar features)
3.  Fase 2  → Componentes base atómicos
4.  Fase 3  → Módulo auth
5.  Fase 4  → Módulo users (mayor complejidad)
6.  Fase 5  → Módulo roles
7.  Fase 6  → Módulo permissions
8.  Fase 7  → Módulo dashboard
9.  Fase 8  → Router global
10. Fase 9  → Estilos
11. Fase 10 → Limpieza de legacy
```

---

## Reglas de Migración para el Agente

1. **Nunca romper la app entre fases** — cada fase debe dejar la app funcional y compilable.
2. **Mover antes de reescribir** — primero mover el archivo, luego mejorar.
3. **Re-exports temporales** — durante la migración, los archivos movidos deben tener re-exports en la ubicación original para no romper imports existentes.
4. **Vue Query reemplaza stores de fetching** — los stores solo mantienen UI state (modal abierto, filtros activos, elemento seleccionado).
5. **Máximo 200 líneas por componente** — si supera, extraer sub-componentes.
6. **Skeleton loading obligatorio** — toda página debe mostrar skeleton mientras carga.
7. **Tipado estricto** — no `any`, props y emits tipados, sin type assertions innecesarias.
8. **Verificar compilación** — ejecutar `npm run type-check` al final de cada fase.

---

## Métricas de Éxito

| Métrica | Actual | Objetivo |
|---|---|---|
| Componentes > 200 líneas | 3+ | 0 |
| Módulos desacoplados | 0 | 5 |
| Rutas con lazy loading | 0 | 100% |
| Vistas con skeleton | 0 | 100% |
| Stores con server state | 4 | 0 (→ Vue Query) |
| Mappers de datos | 0 | 1 por módulo |
| Composables por módulo | 0 | 5-8 por módulo |

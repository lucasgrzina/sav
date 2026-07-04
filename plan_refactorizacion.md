╭─── Claude Code v2.1.139 ─────────────────────────────────────────────────────╮
│                                                    │ Tips for getting        │
│                 Welcome back Lucas!                │ started                 │
│                                                    │ Run /init to create a … │
│                       ▐▛███▜▌                      │ ─────────────────────── │
│                      ▝▜█████▛▘                     │ What's new              │
│                        ▘▘ ▝▝                       │ Added agent view (Rese… │
│   Sonnet 4.6 · Claude Pro ·                        │ Added `/goal` command:… │
│   lgrzina@identidad-digital.com.ar's Organization  │ Added `/scroll-speed` … │
│                 C:\laragon\www\sav                 │ /release-notes for more │
╰──────────────────────────────────────────────────────────────────────────────╯

❯ /clear                                                                        
  ⎿  (no content)

❯ usa @docs\refactorizacion.md como guia base para hacer un plan de             
  refactorizacion del front. No codifiques, solo haz analisis y muestramos como 
   lo estructurarias y realiza el plan para que luego un agente lo implemente   
  ⎿  Read docs\refactorizacion.md (481 lines)

● Voy a explorar la estructura actual del proyecto antes de armar el plan.

● Explore(Explorar estructura actual del frontend)
  ⎿  Done (50 tool uses · 82.0k tokens · 1m 43s)
  (ctrl+o to expand)

● Tengo toda la información necesaria. Voy a elaborar el plan completo de
  refactorización.

  ---
  Plan de Refactorización Frontend — VetAlert

  Guía base: docs/refactorizacion.md
  Stack objetivo: Vue 3 + TS Strict + Pinia + Vue Query + Vee-Validate + Zod +
  Axios + Tailwind + Atomic Design + Feature Modules

  ---
  Diagnóstico: Estado Actual vs. Objetivo

  Brechas identificadas

  Área: Estructura
  Estado actual: views/ plana + store/ global
  Objetivo: Feature Modules desacoplados
  ────────────────────────────────────────
  Área: API layer
  Estado actual: Axios directo en stores
  Objetivo: /api + mappers por módulo
  ────────────────────────────────────────
  Área: Stores
  Estado actual: Pinia con fetching + UI state mezclados
  Objetivo: Pinia solo para UI/global; Vue Query para server state
  ────────────────────────────────────────
  Área: Componentes
  Estado actual: Átomos parciales, vistas monolíticas
  Objetivo: Atomic Design completo
  ────────────────────────────────────────
  Área: Lazy loading
  Estado actual: Sin lazy loading de rutas
  Objetivo: Dynamic imports por módulo
  ────────────────────────────────────────
  Área: Tipos
  Estado actual: Solo en types/api/ (parciales)
  Objetivo: Tipado estricto por módulo
  ────────────────────────────────────────
  Área: Validadores
  Estado actual: En validators/ global
  Objetivo: Por módulo
  ────────────────────────────────────────
  Área: Composables
  Estado actual: 5 composables básicos
  Objetivo: Composables por entidad + core
  ────────────────────────────────────────
  Área: Skeletons
  Estado actual: Ausentes
  Objetivo: Obligatorios en todas las vistas
  ────────────────────────────────────────
  Área: Error states
  Estado actual: Parciales
  Objetivo: Mandatory por vista
  ────────────────────────────────────────
  Área: Mappers
  Estado actual: Ausentes
  Objetivo: DTO mapper por módulo

  Componentes monolíticos detectados (> 200 líneas)

  - UsersView.vue — CRUD completo embebido en una vista (tabla + 4 modales +
  filtros)
  - RoleFormView.vue — Formulario + lógica de permisos en una sola vista
  - DashboardLayout.vue — Layout + sidebar + header + user menu + theme toggle

  ---
  Estructura Objetivo

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
  │   │   │   └── BasePagination.vue      (MOVER desde
  shared/TablePagination.vue)
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
  │   │   │   ├── AuthFormField.vue       (MOVER — revisar si se reemplaza por
  FormInput)
  │   │   │   └── AuthServerError.vue     (MOVER desde components/auth/)
  │   │   ├── pages/
  │   │   │   ├── LoginPage.vue           (RENOMBRAR ForgotPasswordView →
  LoginPage)
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
  │   │   │   └── UsersPage.vue           (REFACTOR de UsersView — solo
  orquesta)
  │   │   ├── router/
  │   │   │   └── users.routes.ts         (EXTRAER del router global)
  │   │   ├── stores/
  │   │   │   └── users.store.ts          (REDUCIR — solo UI state; data → Vue
  Query)
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
  │           └── dashboard.store.ts      (REFACTOR — reemplazar mock por Vue
  Query)
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
  │   └── ui.store.ts                     (NUEVO — modales, drawers, loaders
  globales)
  │
  └── styles/
      ├── main.css                        (REFACTOR — extraer variables)
      ├── variables.css                   (EXTRAER de main.css)
      ├── animations.css                  (EXTRAER de main.css)
      └── transitions.css                 (EXTRAER de main.css)

  ---
  Fases de Implementación

  Fase 0 — Instalación de dependencias faltantes

  Objetivo: Completar el stack sin romper nada.

  Instalar:
  - @tanstack/vue-query       (Vue Query — server state)
  - @vueuse/core              (utilities reactivas)

  Configurar:
  - core/plugins/vue-query.ts   (QueryClient + VueQueryPlugin)
  - core/plugins/pinia.ts       (extraer de main.ts)
  - core/plugins/antd.ts        (extraer de main.ts)
  - core/plugins/i18n.ts        (extraer de main.ts)
  - main.ts                     (importar plugins, limpiar)

  ---
  Fase 1 — Core Layer

  Objetivo: Establecer la base compartida que todos los módulos van a consumir.

  1.1 API Core
  - Mover src/api/http.ts → src/core/api/http.ts
  - Extraer interceptores en archivos separados:
    - core/api/interceptors/auth.interceptor.ts
    - core/api/interceptors/error.interceptor.ts

  1.2 Tipos globales
  - Crear core/types/api.types.ts — wrapper genérico de respuestas, errores
  - Crear core/types/pagination.types.ts — PaginatedResponse<T>,
  PaginationParams
  - Crear core/types/ui.types.ts — SelectOption, TableColumn<T>, ModalConfig

  1.3 Composables core
  - useDebounce.ts — debounce reactivo
  - usePagination.ts — manejo estándar de paginación
  - useModal.ts — open/close/data pattern
  - useDrawer.ts — idem para drawers
  - useResponsive.ts — breakpoints reactivos
  - Mover useConfirm.ts, usePermissions.ts, useNotification.ts

  1.4 Constants
  - core/constants/routes.ts — constantes de rutas (evitar strings hardcodeados)
  - core/constants/permissions.ts — claves de permisos
  - Mover config/constants.ts → core/constants/app.ts

  1.5 Utils
  - core/utils/date.ts — formatters de fecha
  - core/utils/string.ts — capitalize, truncate
  - core/utils/currency.ts — formatters monetarios

  ---
  Fase 2 — Componentes Base (Atomic Design)

  Objetivo: Biblioteca de componentes reutilizables completa.

  2.1 Atoms — Feedback (prioritarios)
  - BaseSkeleton.vue — wrappea a-skeleton, configurable (líneas, avatar, custom)
  - BaseSpinner.vue — wrappea a-spin
  - BaseEmptyState.vue — mejorar EmptyState.vue existente
  - BaseErrorState.vue — estado de error con retry
  - BaseAlert.vue — wrappea a-alert

  2.2 Atoms — Overlays
  - BaseModal.vue — wrapper genérico de a-modal con slots header/footer
  - BaseDrawer.vue — wrapper genérico de a-drawer
  - Mover ConfirmDialog.vue → BaseConfirmDialog.vue

  2.3 Atoms — Inputs
  - Completar BaseInput.vue (ya existe, revisar)
  - BasePasswordInput.vue — input password con toggle
  - BaseSearchInput.vue — input search con debounce integrado
  - BaseTextarea.vue
  - BaseSelect.vue — wrappea a-select
  - BaseMultiSelect.vue
  - BaseDateRangePicker.vue

  2.4 Atoms — Display
  - BaseBadge.vue — wrappea a-badge
  - BaseTag.vue — wrappea a-tag con colores semánticos
  - BaseAvatar.vue — wrappea a-avatar

  2.5 Atoms — Navigation
  - BasePagination.vue — refactor de TablePagination.vue

  2.6 Molecules — Forms
  - FormInput.vue — FormFieldWrapper + BaseInput + error display
  - FormSelect.vue — ídem con BaseSelect
  - FormDateRangePicker.vue
  - FormCheckbox.vue
  - FormSwitch.vue

  2.7 Molecules — Filters
  - StatusFilter.vue — select de estado genérico
  - DateRangeFilter.vue — rango de fechas genérico

  2.8 Layouts — Descomposición
  - Extraer de DashboardLayout.vue:
    - AppSidebar.vue — sidebar colapsable con menú
    - AppMenu.vue — menú de navegación
    - AppUserMenu.vue — dropdown de usuario
  - DashboardLayout.vue queda como orquestador < 100 líneas

  ---
  Fase 3 — Módulo Auth

  Objetivo: Migrar todo lo relacionado con autenticación al módulo.

  3.1 API
  - Mover src/api/auth.ts → modules/auth/api/auth.api.ts
  - Crear modules/auth/api/auth.mapper.ts — mapear respuesta del servidor a
  tipos internos

  3.2 Store
  - Mover store/auth.ts → modules/auth/stores/auth.store.ts
  - Mantener re-export en stores/auth.store.ts (para no romper imports
  existentes durante migración)

  3.3 Composables
  - Descomponer useAuth.ts en:
    - modules/auth/composables/useLogin.ts
    - modules/auth/composables/useRegister.ts
    - modules/auth/composables/useForgotPassword.ts

  3.4 Tipos y Validators
  - Mover types/api/auth.types.ts → modules/auth/types/auth.types.ts
  - Mover validators/auth.validators.ts →
  modules/auth/validators/auth.validator.ts

  3.5 Componentes
  - Mover components/auth/ → modules/auth/components/
  - Revisar si AuthFormField.vue puede reemplazarse por FormInput.vue de
  molecules

  3.6 Páginas
  - Mover y renombrar views/auth/*.vue → modules/auth/pages/*.vue (View → Page)

  3.7 Router
  - Extraer rutas de auth de router/index.ts →
  modules/auth/router/auth.routes.ts
  - Configurar lazy loading con () => import(...)

  ---
  Fase 4 — Módulo Users

  Objetivo: Descomponer UsersView.vue (monolítico) en módulo completo.

  4.1 Tipos
  - modules/users/types/user.types.ts:
    - User, UserItem, UserCreatePayload, UserUpdatePayload
    - ChangePasswordPayload, UserFilters, UserStatus
  - modules/users/types/user.enums.ts — UserStatus enum

  4.2 API + Mapper
  - Mover src/api/users.ts → modules/users/api/users.api.ts
  - Crear modules/users/api/users.mapper.ts:
    - toUserItem(raw): UserItem
    - toCreatePayload(form): UserCreatePayload
    - toUpdatePayload(form): UserUpdatePayload

  4.3 Composables (Vue Query)
  - useUsers.ts — useQuery para lista paginada + filtros
  - useUser.ts — useQuery para detalle por guid
  - useCreateUser.ts — useMutation + invalidate query
  - useUpdateUser.ts — useMutation + invalidate query
  - useDeleteUser.ts — useMutation + invalidate query + confirm
  - useUserFilters.ts — estado reactivo de filtros con debounce
  - useChangePassword.ts — useMutation
  - useToggleLock.ts — useMutation + optimistic update

  4.4 Store
  - users.store.ts — SOLO UI state: modal activo, usuario seleccionado, drawer
  abierto
  - El server state lo maneja Vue Query

  4.5 Componentes (extraídos de UsersView)
  - UsersTable.vue — recibe users[], loading, total; emite acciones
  - UserFilters.vue — recibe/emite filtros
  - UserStatusBadge.vue — atom de estado (activo/bloqueado)
  - forms/UserForm.vue — vee-validate + Zod; crea/edita usuario
  - forms/ChangePasswordForm.vue — formulario cambio de contraseña
  - modals/CreateUserModal.vue — BaseModal + UserForm
  - modals/EditUserModal.vue — BaseModal + UserForm pre-poblado
  - modals/DeleteUserModal.vue — BaseConfirmDialog tipado
  - modals/ChangePasswordModal.vue — BaseModal + ChangePasswordForm

  4.6 Página
  - UsersPage.vue — orquesta componentes, no tiene lógica de negocio (< 100
  líneas)

  4.7 Router + Validators
  - users.routes.ts con lazy loading
  - user.validator.ts, change-password.validator.ts

  ---
  Fase 5 — Módulo Roles

  Objetivo: Descomponer RoleFormView.vue y RolesListView.vue.

  5.1 API + Mapper
  - Mover src/api/roles.ts → modules/roles/api/roles.api.ts
  - Crear modules/roles/api/roles.mapper.ts

  5.2 Composables (Vue Query)
  - useRoles.ts — lista con filtros
  - useRole.ts — detalle por guid
  - useCreateRole.ts, useUpdateRole.ts, useDeleteRole.ts

  5.3 Componentes
  - RolesTable.vue — tabla extraída de RolesListView
  - RoleForm.vue — formulario extraído de RoleFormView
  - RolePermissionsSelector.vue — lógica de selección de permisos (hoy embedded
  en RoleFormView)

  5.4 Tipos
  - Mover types/api/roles.types.ts → modules/roles/types/role.types.ts

  5.5 Store + Router
  - roles.store.ts — solo UI state
  - roles.routes.ts con lazy loading

  ---
  Fase 6 — Módulo Permissions

  Objetivo: Encapsular gestión de permisos.

  - Mover src/api/permissions.ts → modules/permissions/api/permissions.api.ts
  - Crear usePermissions.ts con Vue Query (reemplaza store actual)
  - permissions.store.ts — refactor del store actual
  - Crear permission.types.ts

  ---
  Fase 7 — Módulo Dashboard

  Objetivo: Reemplazar mock data por API real; descomponer vista.

  - Crear dashboard.api.ts con endpoints reales (o mantener mock como fallback)
  - Crear composables Vue Query: useMetrics, useAlerts, useActivity
  - Mover componentes dashboard/ → modules/dashboard/components/
  - Refactor DashboardPage.vue con skeleton loading
  - dashboard.store.ts — eliminar mock data

  ---
  Fase 8 — Router Global

  Objetivo: Router limpio que importa módulos con lazy loading.

  // router/index.ts (resultado final)
  import { authRoutes } from '@/modules/auth/router/auth.routes'
  import { usersRoutes } from '@/modules/users/router/users.routes'
  import { rolesRoutes } from '@/modules/roles/router/roles.routes'
  import { dashboardRoutes } from '@/modules/dashboard/router/dashboard.routes'

  const routes = [
    ...authRoutes,
    {
      path: '/',
      component: DashboardLayout,
      children: [
        ...dashboardRoutes,
        ...usersRoutes,
        ...rolesRoutes,
      ]
    }
  ]

  Guards separados:
  - guards/auth.guard.ts — requiere autenticación
  - guards/guest.guard.ts — solo para no autenticados

  ---
  Fase 9 — Estilos

  Objetivo: CSS mantenible y organizado.

  - Extraer de main.css:
    - styles/variables.css — CSS custom properties (colores, tipografía,
  spacing)
    - styles/animations.css — keyframes (ECG, fade, etc.)
    - styles/transitions.css — transiciones de Vue
    - styles/main.css — solo imports + resets

  ---
  Orden de Ejecución para el Agente

  1. Fase 0  → Dependencias + plugins
  2. Fase 1  → Core layer (sin tocar features)
  3. Fase 2  → Componentes base (en paralelo con Fase 1)
  4. Fase 3  → Módulo auth (migración + lazy loading)
  5. Fase 4  → Módulo users (mayor complejidad)
  6. Fase 5  → Módulo roles
  7. Fase 6  → Módulo permissions
  8. Fase 7  → Módulo dashboard
  9. Fase 8  → Router global
  10. Fase 9 → Estilos
  11. Limpieza → Eliminar carpetas legacy (src/views/, src/store/, src/api/)

  ---
  Reglas de Migración para el Agente

  1. Nunca romper la app entre fases — cada fase debe dejar la app funcional.
  2. Mover antes de reescribir — primero mover el archivo, luego mejorar.
  3. Re-exports temporales — durante la migración, los archivos movidos deben
  tener re-exports en la ubicación original para no romper imports.
  4. Vue Query reemplaza stores de fetching — stores solo mantienen UI state
  (modal abierto, filtros activos, elemento seleccionado).
  5. Máximo 200 líneas por componente — si supera, extraer sub-componentes.
  6. Skeleton loading obligatorio — toda página debe mostrar skeleton mientras
  carga.
  7. Tipado estricto — no any, props y emits tipados, sin type assertions
  innecesarias.

  ---
  Métricas de Éxito

  ┌──────────────────────────┬────────┬─────────────────┐
  │         Métrica          │ Actual │    Objetivo     │
  ├──────────────────────────┼────────┼─────────────────┤
  │ Componentes > 200 líneas │ 3+     │ 0               │
  ├──────────────────────────┼────────┼─────────────────┤
  │ Módulos desacoplados     │ 0      │ 5               │
  ├──────────────────────────┼────────┼─────────────────┤
  │ Rutas con lazy loading   │ 0      │ 100%            │
  ├──────────────────────────┼────────┼─────────────────┤
  │ Vistas con skeleton      │ 0      │ 100%            │
  ├──────────────────────────┼────────┼─────────────────┤
  │ Stores con server state  │ 4      │ 0 (→ Vue Query) │
  ├──────────────────────────┼────────┼─────────────────┤
  │ Mappers de datos         │ 0      │ 1 por módulo    │
  ├──────────────────────────┼────────┼─────────────────┤
  │ Composables por módulo   │ 0      │ 5-8 por módulo  │
  └──────────────────────────┴────────┴─────────────────┘

✻ Churned for 3m 45s
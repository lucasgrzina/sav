refactorizar el front a una estructura mas moderna y escalable para llevarlo a desarrollos grandes.
Te voy a darconsignas, recomendaciones y ejemplos para que lo uses.

Actúa como un Frontend Architect Senior especializado en Vue 3 + TypeScript.

OBJETIVO:
Generar módulos enterprise desacoplados, escalables, reutilizables y performantes.


Base técnica
Vue 3
TS strict
Vite
Pinia
Vue Query
Tailwind
Ant Design Vue 
Base arquitectónica
Atomic Design
Feature Modules


STACK OBLIGATORIO:
- Vue 3 Composition API
- TypeScript estricto
- Pinia
- Vue Query
- Vee Validate
- Zod
- Axios
- Tailwind
- Atomic Design

REGLAS ARQUITECTÓNICAS:
- Todo debe ser desacoplado.
- No repetir lógica.
- No hacer componentes gigantes.
- Máximo 200 líneas por componente.
- Toda lógica reutilizable debe ir en composables.
- Toda llamada API debe ir en /api.
- Toda transformación de datos debe ir en mappers.
- No usar lógica inline en templates.
- Separar UI de lógica.
- Los formularios deben ser genéricos y reutilizables.
- Todas las tablas deben soportar:
  - loading
  - empty state
  - pagination
  - sorting
  - filters
  - responsive
- Todo listado debe soportar virtualización si supera 100 registros.
- Todas las vistas deben usar skeleton loading.
- Todos los módulos deben seguir EXACTAMENTE la misma estructura.

ESTRUCTURA RECOMENDADA:
src/
├── App.vue
├── main.ts
│
├── core/
│   ├── api/
│   │   ├── http.ts
│   │   ├── interceptors/
│   │   │   ├── auth.interceptor.ts
│   │   │   ├── error.interceptor.ts
│   │   │   └── refresh.interceptor.ts
│   │   └── types/
│   │
│   ├── composables/
│   │   ├── useDebounce.ts
│   │   ├── usePagination.ts
│   │   ├── useModal.ts
│   │   ├── useDrawer.ts
│   │   ├── usePermissions.ts
│   │   ├── useResponsive.ts
│   │   └── useConfirm.ts
│   │
│   ├── constants/
│   │   ├── routes.ts
│   │   ├── permissions.ts
│   │   └── app.ts
│   │
│   ├── plugins/
│   │   ├── antd.ts
│   │   ├── vue-query.ts
│   │   ├── pinia.ts
│   │   ├── vee-validate.ts
│   │   └── i18n.ts
│   │
│   ├── services/
│   │   ├── auth.service.ts
│   │   ├── storage.service.ts
│   │   ├── notification.service.ts
│   │   └── download.service.ts
│   │
│   ├── types/
│   │   ├── api.types.ts
│   │   ├── pagination.types.ts
│   │   └── ui.types.ts
│   │
│   └── utils/
│       ├── date.ts
│       ├── currency.ts
│       ├── string.ts
│       ├── mask.ts
│       ├── validation.ts
│       └── download.ts
│
├── components/
│   │
│   ├── atoms/
│   │   │
│   │   ├── buttons/
│   │   │   ├── BaseButton.vue
│   │   │   ├── BaseIconButton.vue
│   │   │   └── BaseFloatingButton.vue
│   │   │
│   │   ├── inputs/
│   │   │   ├── BaseInput.vue
│   │   │   ├── BaseTextarea.vue
│   │   │   ├── BasePasswordInput.vue
│   │   │   ├── BaseSearchInput.vue
│   │   │   ├── BaseNumberInput.vue
│   │   │   ├── BaseCurrencyInput.vue
│   │   │   ├── BasePhoneInput.vue
│   │   │   └── BaseOtpInput.vue
│   │   │
│   │   ├── selects/
│   │   │   ├── BaseSelect.vue
│   │   │   ├── BaseAsyncSelect.vue
│   │   │   ├── BaseMultiSelect.vue
│   │   │   └── BaseAutocomplete.vue
│   │   │
│   │   ├── pickers/
│   │   │   ├── BaseDatePicker.vue
│   │   │   ├── BaseDateRangePicker.vue
│   │   │   ├── BaseTimePicker.vue
│   │   │   └── BaseMonthPicker.vue
│   │   │
│   │   ├── feedback/
│   │   │   ├── BaseSpinner.vue
│   │   │   ├── BaseSkeleton.vue
│   │   │   ├── BaseAlert.vue
│   │   │   ├── BaseEmptyState.vue
│   │   │   ├── BaseErrorState.vue
│   │   │   └── BaseProgress.vue
│   │   │
│   │   ├── display/
│   │   │   ├── BaseAvatar.vue
│   │   │   ├── BaseBadge.vue
│   │   │   ├── BaseTag.vue
│   │   │   ├── BaseTooltip.vue
│   │   │   ├── BaseChip.vue
│   │   │   └── BaseStat.vue
│   │   │
│   │   ├── overlays/
│   │   │   ├── BaseModal.vue
│   │   │   ├── BaseDrawer.vue
│   │   │   ├── BasePopover.vue
│   │   │   ├── BaseDropdown.vue
│   │   │   └── BaseConfirmDialog.vue
│   │   │
│   │   ├── navigation/
│   │   │   ├── BaseTabs.vue
│   │   │   ├── BaseBreadcrumb.vue
│   │   │   ├── BasePagination.vue
│   │   │   └── BaseSteps.vue
│   │   │
│   │   ├── tables/
│   │   │   ├── BaseDataTable.vue
│   │   │   ├── BaseTableActions.vue
│   │   │   └── BaseTableEmpty.vue
│   │   │
│   │   ├── cards/
│   │   │   ├── BaseCard.vue
│   │   │   ├── BaseStatsCard.vue
│   │   │   └── BaseInfoCard.vue
│   │   │
│   │   └── typography/
│   │       ├── BaseTitle.vue
│   │       ├── BaseText.vue
│   │       ├── BaseLabel.vue
│   │       └── BaseLink.vue
│   │
│   ├── molecules/
│   │   │
│   │   ├── forms/
│   │   │   ├── FormFieldWrapper.vue
│   │   │   ├── FormInput.vue
│   │   │   ├── FormTextarea.vue
│   │   │   ├── FormSelect.vue
│   │   │   ├── FormAsyncSelect.vue
│   │   │   ├── FormMultiSelect.vue
│   │   │   ├── FormDatePicker.vue
│   │   │   ├── FormDateRangePicker.vue
│   │   │   ├── FormCheckbox.vue
│   │   │   ├── FormSwitch.vue
│   │   │   ├── FormRadioGroup.vue
│   │   │   ├── FormCurrencyInput.vue
│   │   │   ├── FormFileUpload.vue
│   │   │   ├── FormEditor.vue
│   │   │   ├── FormOtpInput.vue
│   │   │   ├── DynamicForm.vue
│   │   │   └── resolveField.ts
│   │   │
│   │   ├── filters/
│   │   │   ├── SearchFilter.vue
│   │   │   ├── StatusFilter.vue
│   │   │   ├── DateRangeFilter.vue
│   │   │   └── AdvancedFilters.vue
│   │   │
│   │   ├── search/
│   │   │   ├── GlobalSearch.vue
│   │   │   └── CommandPalette.vue
│   │   │
│   │   ├── cards/
│   │   │   ├── UserCard.vue
│   │   │   ├── ProductCard.vue
│   │   │   └── StatCard.vue
│   │   │
│   │   ├── auth/
│   │   │   ├── LoginForm.vue
│   │   │   ├── ForgotPasswordForm.vue
│   │   │   └── ResetPasswordForm.vue
│   │   │
│   │   └── tables/
│   │       ├── TableToolbar.vue
│   │       ├── TableFilters.vue
│   │       ├── TablePagination.vue
│   │       └── TableSearch.vue
│   │
│   ├── layouts/
│   │   │
│   │   ├── DashboardLayout.vue
│   │   ├── AuthLayout.vue
│   │   ├── EmptyLayout.vue
│   │   │
│   │   ├── partials/
│   │   │   ├── AppSidebar.vue
│   │   │   ├── AppHeader.vue
│   │   │   ├── AppFooter.vue
│   │   │   ├── AppMenu.vue
│   │   │   ├── AppNotifications.vue
│   │   │   └── AppUserMenu.vue
│   │   │
│   │   └── templates/
│   │       ├── CrudPageTemplate.vue
│   │       ├── DashboardPageTemplate.vue
│   │       ├── DetailsPageTemplate.vue
│   │       ├── SettingsPageTemplate.vue
│   │       └── WizardPageTemplate.vue
│   │
│   └── shared/
│       ├── PermissionGuard.vue
│       ├── ErrorBoundary.vue
│       ├── ConfirmAction.vue
│       └── AsyncContent.vue
│
├── modules/
│   │
│   ├── auth/
│   │   ├── api/
│   │   ├── composables/
│   │   ├── components/
│   │   ├── pages/
│   │   ├── router/
│   │   ├── stores/
│   │   ├── validators/
│   │   └── types/
│   │
│   ├── users/
│   │   │
│   │   ├── api/
│   │   │   ├── users.api.ts
│   │   │   ├── passwords.api.ts
│   │   │   └── users.mapper.ts
│   │   │
│   │   ├── composables/
│   │   │   ├── useUsers.ts
│   │   │   ├── useUser.ts
│   │   │   ├── useCreateUser.ts
│   │   │   ├── useUpdateUser.ts
│   │   │   ├── useDeleteUser.ts
│   │   │   ├── useUserFilters.ts
│   │   │   ├── useChangePassword.ts
│   │   │   └── useResetPassword.ts
│   │   │
│   │   ├── components/
│   │   │   ├── UsersTable.vue
│   │   │   ├── UserFilters.vue
│   │   │   ├── UserForm.vue
│   │   │   ├── UserStatusBadge.vue
│   │   │   ├── UserSecurityCard.vue
│   │   │   │
│   │   │   ├── forms/
│   │   │   │   ├── ChangePasswordForm.vue
│   │   │   │   └── ResetPasswordForm.vue
│   │   │   │
│   │   │   ├── drawers/
│   │   │   │   ├── CreateUserDrawer.vue
│   │   │   │   └── EditUserDrawer.vue
│   │   │   │
│   │   │   └── modals/
│   │   │       ├── DeleteUserModal.vue
│   │   │       ├── ChangePasswordModal.vue
│   │   │       └── ResetPasswordModal.vue
│   │   │
│   │   ├── pages/
│   │   │   ├── UsersPage.vue
│   │   │   ├── UserDetailsPage.vue
│   │   │   └── UserProfilePage.vue
│   │   │
│   │   ├── router/
│   │   │   └── users.routes.ts
│   │   │
│   │   ├── stores/
│   │   │   └── users.store.ts
│   │   │
│   │   ├── validators/
│   │   │   ├── user.validator.ts
│   │   │   ├── change-password.validator.ts
│   │   │   └── reset-password.validator.ts
│   │   │
│   │   ├── types/
│   │   │   ├── user.types.ts
│   │   │   └── user.enums.ts
│   │   │
│   │   └── constants/
│   │       └── users.constants.ts
│   │
│   ├── roles/
│   ├── permissions/
│   ├── products/
│   ├── invoices/
│   ├── dashboard/
│   └── settings/
│
├── router/
│   ├── index.ts
│   ├── guards/
│   │   ├── auth.guard.ts
│   │   ├── guest.guard.ts
│   │   └── permission.guard.ts
│   └── routes.ts
│
├── stores/
│   ├── auth.store.ts
│   ├── app.store.ts
│   └── ui.store.ts
│
├── styles/
│   ├── main.css
│   ├── variables.css
│   ├── animations.css
│   └── transitions.css
│
├── assets/
│   ├── images/
│   ├── icons/
│   └── fonts/
│
├── docs/
│   └── ai/
│       ├── AI_FRONTEND_RULES.md
│       ├── EXAMPLES.md
│       ├── ANTI_PATTERNS.md
│       └── MODULE_TEMPLATE.md
│
└── tests/
    ├── unit/
    ├── integration/
    └── e2e/

PATRONES OBLIGATORIOS:
- Container/Presentation
- Repository Pattern
- Composables Pattern
- DTO Pattern
- Factory Pattern para tablas y formularios
- SOLID
- DRY
- KISS

CONVENCIONES:
- PascalCase para componentes
- camelCase para funciones
- kebab-case para rutas
- Tipado estricto
- No usar any
- Props tipadas
- Emits tipados

PERFORMANCE:
- Lazy loading de rutas
- Dynamic imports
- computed antes que watch
- memoización cuando aplique
- evitar rerenders
- evitar watchers innecesarios

CUANDO GENERES UN MÓDULO:
1. Crear estructura completa
2. Crear tipos
3. Crear api layer
4. Crear composables
5. Crear store
6. Crear tabla reutilizable
7. Crear formulario reutilizable
8. Crear vistas
9. Crear validaciones
10. Crear loading/error states

IMPORTANTE:
Siempre reutilizar componentes existentes antes de crear nuevos.

PROHIBIDO:
- lógica de negocio en componentes
- any
- watchers innecesarios
- props drilling profundo
- componentes > 200 líneas
- duplicación
- estilos inline
- axios directo en vistas
- estado duplicado


EJEMPLO de estructura modulo Users

Estructura completa del módulo users
src/
├── modules/
│   └── users/
│       ├── api/
│       │   ├── users.api.ts
│       │   └── users.mapper.ts
│       │
│       ├── components/
│       │   ├── UserFilters.vue
│       │   ├── UserForm.vue
│       │   ├── UsersTable.vue
│       │   ├── UserStatusBadge.vue
│       │   └── drawers/
│       │       └── UserDrawer.vue
│       │
│       ├── composables/
│       │   ├── useUsers.ts
│       │   ├── useCreateUser.ts
│       │   ├── useUpdateUser.ts
│       │   ├── useDeleteUser.ts
│       │   └── useUserFilters.ts
│       │
│       ├── pages/
│       │   └── UsersPage.vue
│       │
│       ├── router/
│       │   └── users.routes.ts
│       │
│       ├── stores/
│       │   └── users.store.ts
│       │
│       ├── types/
│       │   ├── user.types.ts
│       │   └── user.enums.ts
│       │
│       ├── validators/
│       │   └── user.validator.ts
│       │
│       └── constants/
│           └── users.constants.ts
│
├── components/
│   ├── atoms/
│   ├── molecules/
│   └── layouts/
│
├── core/
│   ├── api/
│   │   └── http.ts
│   └── utils/
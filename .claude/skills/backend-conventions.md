# Convenciones de arquitectura backend SAV (Laravel)

Estas convenciones son no negociables para todo código backend en SAV.

## Pattern Repository

- Interface en `back/app/Contracts/Repositories/I{Nombre}Repository.php`.
- Implementación Eloquent en `back/app/Repositories/{Nombre}Repository.php`.
- Binding en `back/app/Providers/AppServiceProvider.php` método `register()`.
- El Service inyecta la Interface, **nunca** el Model directamente.

## Service Layer

- Toda lógica de negocio va en `back/app/Services/`. Los controllers son delgados.
- Métodos tipados: `public function metodo(Tipo $param): TipoRetorno`.
- `DB::transaction()` en operaciones de escritura que toquen múltiples tablas.

## Controllers

- `ApiResponseTrait` en todos los controllers. Respuesta estándar: `{ success, data, message?, errors? }`.
- Namespace `V1`: `back/app/Http/Controllers/V1/`.
- Reciben `string $guid`, nunca `$id`.
- Mínima lógica: validar request → llamar service → retornar resource.

## Resources

- Namespace `V1`: `back/app/Http/Resources/V1/`.
- NUNCA exponer `id` interno. Siempre incluir `guid`.
- `$hidden = ['id']` en el modelo.

## Form Requests

- Un Request por endpoint de escritura: `Store{Nombre}Request`, `Update{Nombre}Request`.
- `authorize()` retorna `true`.
- `messages()` con mensajes en español.

## Modelos

- Trait `HasGuid` obligatorio en modelos nuevos.
- `getRouteKeyName()` retorna `'guid'`.
- `$fillable` sin `guid` ni timestamps (los maneja automáticamente).
- `$hidden = ['id']`.
- Sin `softDeletes()` — el proyecto no los usa.

## Rutas

- Archivo propio en `back/routes/api/{nombre}.php` (kebab-case, plural).
- Prefijo `v1`, middleware `auth:sanctum`.
- Incluir en `back/routes/api.php`.

## Permisos (Spatie)

- Patrón: `{modulo}.lectura`, `{modulo}.alta`, `{modulo}.modificacion`, `{modulo}.baja`.
- Guard `'web'` siempre. NO usar `'sanctum'` para roles/permisos.
- Agregar en `PermissionSeeder` y asignar a roles en `RoleSeeder`.

## Migraciones

- `guid` como `string(36)->unique()`.
- Foreign keys con `->constrained()->cascadeOnDelete()`.
- Sin `softDeletes()`.
- `->comment()` en columnas no obvias.

## Seeders

- `WithoutModelEvents` siempre.
- `guid` seteado EXPLÍCITAMENTE con `Str::uuid()->toString()` — NO depender del boot del modelo.

# Plan técnico: Exportación de datos en múltiples formatos

## Input procesado
Requerimiento directo provisto por el usuario en el chat (2026-05-18).
No hay archivo de spec previo en `.claude/docs/specs/` ni ticket en `.claude/docs/tickets/`.

---

## Resumen ejecutivo

Se implementa un sistema de exportación de datos multi-formato (XLSX, CSV, TXT, PDF) para el proyecto SAV, siguiendo los principios SOLID y el patrón de capas existente (Repository + Service + Controller). El sistema soporta dos modos: **síncrono** (descarga directa para datasets pequeños) y **asíncrono** (Job en cola para datasets grandes, con notificación y link de descarga). Se introduce una tabla `exports` para trackear el ciclo de vida de cada exportación. En el frontend se crea el módulo `exports` con su store Pinia, composables de Vue Query y un componente `ExportButton` reutilizable que se apoya en el composable `useExportFormat` ya existente en el proyecto.

---

## Decisiones tomadas

**DEC-01 — Contratos de exportación: interfaces propias vs. abstracciones de Laravel Excel**
  Decisión: Se definen interfaces propias (`ExporterInterface`, `ExportFormatterInterface`, `ExportResolverInterface`) en `app/Contracts/Exports/`.
  Justificación: Maatwebsite/Excel ya impone su propio sistema de contratos (`FromQuery`, `WithHeadings`, etc.). Las interfaces propias actúan como capa de orquestación por encima del package, permitiendo agregar PDF o TXT sin tocar el código de XLSX/CSV. Open/Closed queda garantizado: agregar un formato = crear una clase nueva que implemente `ExporterInterface` y registrarla en el Resolver.
  Alternativa descartada: Usar directamente `Maatwebsite\Excel\Concerns` como interfaz — acopla todo el sistema al package y hace imposible soportar PDF con la misma abstracción.

**DEC-02 — Threshold síncrono/asíncrono**
  Decisión: El controller determina el modo según un parámetro `async` del request (`bool`, default `false`). Para la iteración inicial el frontend siempre envía `async: false` para datasets pequeños y el usuario puede forzar `async: true` para exportaciones grandes. No se implementa detección automática por conteo de filas en esta iteración.
  Justificación: La detección automática requiere un COUNT previo que puede ser costoso con filtros complejos. El parámetro explícito es más predecible y fácil de testear.
  Alternativa descartada: Detección automática por `COUNT(*) > N` antes de despachar — se puede agregar en una iteración futura como mejora en `ExportService`.

**DEC-03 — Almacenamiento de archivos exportados**
  Decisión: Disco `local` (private), path `exports/{user_guid}/{año-mes}/{uuid}.{ext}`. El endpoint de descarga sirve el archivo mediante `response()->download()` validando que el registro pertenezca al usuario autenticado.
  Justificación: El disco `public` expone URLs directas sin validación de permisos. El disco `local` obliga a pasar por el controller que verifica ownership.
  Alternativa descartada: Disco `public` con URL directa — rompe el control de acceso por usuario.

**DEC-04 — Notificación al completar exportación asíncrona**
  Decisión: Se usa `Illuminate\Notifications\Notification` con el canal `database` (tabla `notifications` de Laravel). El frontend hace polling periódico (cada 10 s) al endpoint de notificaciones no leídas mientras hay exportaciones `processing`. No se implementa WebSocket/SSE en esta iteración.
  Justificación: El proyecto no tiene WebSocket configurado. Polling cada 10 s es suficiente para exportaciones que típicamente tardan < 2 min. El canal `database` de Laravel ya está soportado por la tabla `notifications` (se crea con una migration estándar de Laravel).
  Alternativa descartada: Broadcasting con Pusher/Soketi — requiere infraestructura adicional no presente.

**DEC-05 — Selección de columnas y filtros en el request**
  Decisión: El request de exportación recibe `columns` (array de strings con los nombres de columna a incluir) y `filters` (el mismo shape de filtros que usa el listado, e.g. `search`, `status`, `date_from`, `date_to`). Si `columns` está vacío, se exportan todas las columnas disponibles para ese tipo de exportación.
  Justificación: Reutilizar el mismo objeto de filtros que ya usa el listado evita duplicar lógica de query. El array `columns` es la forma más simple y explícita de selección de columnas.
  Alternativa descartada: Guardar una "configuración de exportación" en BD — sobrediseño para esta iteración.

**DEC-06 — Política de permisos: Policy vs. middleware**
  Decisión: Se crea `ExportPolicy` con método `create(User $user, string $exportType)`. El controller llama `$this->authorize('create', [Export::class, $exportType])`. Además, se agrega el permiso Spatie `exports.alta` que la policy verifica.
  Justificación: Las Policies de Laravel son el mecanismo canónico para lógica de autorización en el dominio. El permiso Spatie mantiene la convención `{modulo}.{accion}` del proyecto. La policy puede extenderse para restricciones por tipo de exportación sin modificar el controller.
  Alternativa descartada: Solo middleware `can:exports.alta` — no permite lógica dinámica por tipo de exportación.

**DEC-07 — Naming de permisos**
  Decisión: Usar `exports.alta` (convención `{modulo}.{accion}` del proyecto). Para la descarga se reutiliza el mismo permiso verificando ownership en la policy.
  Justificación: Consistencia con los permisos existentes (`users.alta`, `roles.lectura`, etc.).

**DEC-08 — Tipos de exportación soportados en el Resolver**
  Decisión: El `ExportTypeEnum` define los tipos de exportación disponibles (e.g. `users`). Cada tipo tiene un "exporter builder" registrado en `ExportResolverService`. Cuando se agrega un módulo nuevo, solo se registra un nuevo builder en el resolver.
  Justificación: Evita un switch gigante en el controller. El resolver centraliza el mapeo tipo→builder.

**DEC-09 — Formato del archivo de exportación: useExportFormat reutilizado en el frontend**
  Decisión: El composable existente `useExportFormat.ts` se extiende para soportar también `csv`. El modal de selección de formato existente se convierte en el componente `ExportFormatModal` que envuelve `useExportFormat`. El `ExportButton` llama a `useExportFormat().open()` y recibe la selección.
  Justificación: `useExportFormat.ts` ya existe y tiene exactamente la abstracción necesaria. No tiene sentido crear algo desde cero.
  Alternativa descartada: Store Pinia separado para el modal de formato — `useExportFormat` ya es un singleton reactivo.

**DEC-10 — Expiración de archivos exportados**
  Decisión: La tabla `exports` tiene campo `expires_at` (por defecto 7 días desde la creación). Se crea un comando Artisan `exports:cleanup` para eliminar archivos vencidos. En esta iteración el comando se registra pero no se schedula automáticamente (eso es config de servidor).
  Justificación: Sin expiración, el disco de storage crece indefinidamente. 7 días es un valor razonable para archivos de uso puntual.

---

## Cambios en BACKEND

### Archivos a crear

#### `back/app/Contracts/Exports/ExporterInterface.php`
**Propósito:** Contrato que toda clase exportadora concreta debe implementar.
```php
namespace App\Contracts\Exports;

interface ExporterInterface
{
    /**
     * Ejecuta la exportación y retorna el path absoluto del archivo generado.
     *
     * @param  array  $filters  Filtros de búsqueda (mismos que el listado).
     * @param  array  $columns  Columnas a incluir. Vacío = todas.
     * @param  string $filePath Path relativo donde guardar (disco local).
     * @return string           Path relativo del archivo generado.
     */
    public function export(array $filters, array $columns, string $filePath): string;

    /**
     * Retorna la extensión de archivo que produce este exporter (xlsx, csv, txt, pdf).
     */
    public function getExtension(): string;

    /**
     * Retorna el MIME type del archivo producido.
     */
    public function getMimeType(): string;
}
```
**Dependencias inyectadas:** ninguna (es una interface).

---

#### `back/app/Contracts/Exports/ExportResolverInterface.php`
**Propósito:** Contrato del resolver que mapea (tipo de exportación, formato) → ExporterInterface concreto.
```php
namespace App\Contracts\Exports;

interface ExportResolverInterface
{
    /**
     * @param  string $exportType  Tipo de dataset a exportar (e.g. 'users').
     * @param  string $format      Formato de archivo (xlsx, csv, txt, pdf).
     * @return ExporterInterface
     * @throws \InvalidArgumentException Si el tipo o formato no están soportados.
     */
    public function resolve(string $exportType, string $format): ExporterInterface;

    /**
     * Retorna los formatos disponibles para un tipo de exportación dado.
     *
     * @return string[]
     */
    public function availableFormats(string $exportType): array;
}
```

---

#### `back/app/Enums/ExportFormat.php`
**Propósito:** Enum de formatos soportados. Centraliza los strings para evitar typos.
```php
namespace App\Enums;

enum ExportFormat: string
{
    case XLSX = 'xlsx';
    case CSV  = 'csv';
    case TXT  = 'txt';
    case PDF  = 'pdf';

    public function mimeType(): string
    {
        return match($this) {
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::CSV  => 'text/csv',
            self::TXT  => 'text/plain',
            self::PDF  => 'application/pdf',
        };
    }

    public function label(): string
    {
        return match($this) {
            self::XLSX => 'Excel (.xlsx)',
            self::CSV  => 'CSV (.csv)',
            self::TXT  => 'Texto (.txt)',
            self::PDF  => 'PDF (.pdf)',
        };
    }
}
```

---

#### `back/app/Enums/ExportStatus.php`
**Propósito:** Estados posibles de una exportación.
```php
namespace App\Enums;

enum ExportStatus: string
{
    case PENDING    = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED  = 'completed';
    case FAILED     = 'failed';
}
```

---

#### `back/app/Enums/ExportType.php`
**Propósito:** Tipos de dataset exportables. Agregar nuevos tipos aquí al extender.
```php
namespace App\Enums;

enum ExportType: string
{
    case USERS = 'users';

    public function label(): string
    {
        return match($this) {
            self::USERS => 'Usuarios',
        };
    }
}
```

---

#### `back/app/Models/Export.php`
**Propósito:** Modelo Eloquent para el registro de exportaciones.
```php
namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Traits\HasGuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Export extends Model
{
    use HasGuid;

    protected $fillable = [
        'guid', 'user_id', 'type', 'format', 'status',
        'file_path', 'file_name', 'filters', 'columns',
        'error_message', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'type'       => ExportType::class,
            'format'     => ExportFormat::class,
            'status'     => ExportStatus::class,
            'filters'    => 'array',
            'columns'    => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === ExportStatus::COMPLETED
            && !$this->isExpired()
            && $this->file_path !== null;
    }
}
```
**Dependencias:** `HasGuid` trait, enums `ExportFormat`, `ExportStatus`, `ExportType`.

---

#### `back/app/Contracts/Repositories/ExportRepositoryInterface.php`
**Propósito:** Contrato del repositorio de exportaciones.
```php
namespace App\Contracts\Repositories;

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ExportRepositoryInterface
{
    public function create(array $data): Export;

    public function findByGuid(string $guid): ?Export;

    public function updateStatus(Export $export, ExportStatus $status, array $extra = []): Export;

    public function listForUser(User $user, int $perPage): LengthAwarePaginator;

    public function deleteExpired(): int;
}
```

---

#### `back/app/Repositories/ExportRepositoryEloquent.php`
**Propósito:** Implementación Eloquent del repositorio de exportaciones.
```php
namespace App\Repositories;

use App\Contracts\Repositories\ExportRepositoryInterface;
use App\Enums\ExportStatus;
use App\Models\Export;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ExportRepositoryEloquent extends BaseRepositoryEloquent implements ExportRepositoryInterface
{
    public function model(): string
    {
        return Export::class;
    }

    public function create(array $data): Export
    {
        return Export::create($data);
    }

    public function findByGuid(string $guid): ?Export
    {
        return Export::where('guid', $guid)->first();
    }

    public function updateStatus(Export $export, ExportStatus $status, array $extra = []): Export
    {
        $export->update(array_merge(['status' => $status], $extra));
        return $export->fresh();
    }

    public function listForUser(User $user, int $perPage): LengthAwarePaginator
    {
        return Export::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function deleteExpired(): int
    {
        // Recupera los paths antes de borrar para limpiar disco
        $expired = Export::where('expires_at', '<', now())
            ->whereNotNull('file_path')
            ->get();

        foreach ($expired as $export) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($export->file_path);
        }

        return Export::where('expires_at', '<', now())->delete();
    }
}
```

---

#### `back/app/Exports/Users/UsersExport.php`
**Propósito:** Clase de exportación de usuarios para Maatwebsite/Excel (XLSX y CSV). Implementa los concerns del package y la interfaz propia a través de un wrapper.
```php
namespace App\Exports\Users;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UsersExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    public function __construct(
        private readonly Collection $users,
        private readonly array $columns,   // columnas seleccionadas (vacío = todas)
    ) {}

    public function collection(): Collection
    {
        return $this->users;
    }

    public function headings(): array
    {
        return $this->filterColumns([
            'guid'             => 'ID',
            'first_name'       => 'Nombre',
            'last_name'        => 'Apellido',
            'email'            => 'Email',
            'status'           => 'Estado',
            'last_login_at'    => 'Último login',
            'created_at'       => 'Fecha de creación',
        ]);
    }

    public function map($user): array
    {
        // pseudocódigo: mapear solo las columnas seleccionadas
        $allColumns = [
            'guid'          => $user->guid,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'status'        => $user->locked_at ? 'Bloqueado' : ($user->email_verified_at ? 'Verificado' : 'No verificado'),
            'last_login_at' => $user->last_login_at?->format('d/m/Y H:i'),
            'created_at'    => $user->created_at?->format('d/m/Y H:i'),
        ];

        return array_values($this->filterColumns($allColumns));
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    // Retorna solo las columnas seleccionadas (o todas si $this->columns está vacío)
    private function filterColumns(array $all): array
    {
        if (empty($this->columns)) {
            return $all;
        }
        return array_intersect_key($all, array_flip($this->columns));
    }
}
```

---

#### `back/app/Exports/Users/UsersExporter.php`
**Propósito:** Implementa `ExporterInterface` para el tipo 'users' + formatos XLSX y CSV. Usa `UsersExport` internamente.
```php
namespace App\Exports\Users;

use App\Contracts\Exports\ExporterInterface;
use App\Enums\ExportFormat;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class UsersExporter implements ExporterInterface
{
    public function __construct(
        private readonly \App\Contracts\Repositories\UserRepositoryInterface $userRepository,
        private readonly ExportFormat $format,
    ) {}

    public function export(array $filters, array $columns, string $filePath): string
    {
        // 1. Recuperar todos los registros sin paginar
        $users = $this->userRepository->exportQuery($filters)->get();

        // 2. Instanciar UsersExport con los datos y columnas
        $exporter = new UsersExport($users, $columns);

        // 3. Usar Maatwebsite/Excel para generar el archivo
        $writerType = match($this->format) {
            ExportFormat::XLSX => \Maatwebsite\Excel\Excel::XLSX,
            ExportFormat::CSV  => \Maatwebsite\Excel\Excel::CSV,
            default            => throw new \InvalidArgumentException("Formato no soportado por UsersExporter"),
        };

        Excel::store($exporter, $filePath, 'local', $writerType);

        return $filePath;
    }

    public function getExtension(): string
    {
        return $this->format->value;
    }

    public function getMimeType(): string
    {
        return $this->format->mimeType();
    }
}
```
**Dependencias inyectadas:** `UserRepositoryInterface`, `ExportFormat` (pasado al constructor por el resolver).

---

#### `back/app/Exports/Users/UsersTxtExporter.php`
**Propósito:** Implementa `ExporterInterface` para el tipo 'users' + formato TXT. Genera archivo de texto delimitado por tabulaciones.
```php
namespace App\Exports\Users;

use App\Contracts\Exports\ExporterInterface;
use Illuminate\Support\Facades\Storage;

class UsersTxtExporter implements ExporterInterface
{
    public function __construct(
        private readonly \App\Contracts\Repositories\UserRepositoryInterface $userRepository,
    ) {}

    public function export(array $filters, array $columns, string $filePath): string
    {
        $users = $this->userRepository->exportQuery($filters)->get();

        $allHeaders = ['guid', 'first_name', 'last_name', 'email', 'status', 'last_login_at', 'created_at'];
        $activeHeaders = empty($columns) ? $allHeaders : array_intersect($allHeaders, $columns);

        $lines = [];
        $lines[] = implode("\t", $activeHeaders);

        foreach ($users as $user) {
            $row = array_map(fn($col) => match($col) {
                'status'        => $user->locked_at ? 'Bloqueado' : ($user->email_verified_at ? 'Verificado' : 'No verificado'),
                'last_login_at' => $user->last_login_at?->format('d/m/Y H:i') ?? '',
                'created_at'    => $user->created_at?->format('d/m/Y H:i') ?? '',
                default         => $user->{$col} ?? '',
            }, $activeHeaders);
            $lines[] = implode("\t", $row);
        }

        Storage::disk('local')->put($filePath, implode(PHP_EOL, $lines));

        return $filePath;
    }

    public function getExtension(): string { return 'txt'; }
    public function getMimeType(): string  { return 'text/plain'; }
}
```

---

#### `back/app/Exports/Pdf/BasePdfExporter.php`
**Propósito:** Clase abstracta que centraliza la generación de PDFs para todos los módulos. Cada exporter concreto solo implementa el título, las columnas y el mapeo de filas. Si no se especifica una vista, usa la genérica (`exports.generic`). Open/Closed: agregar un módulo nuevo = crear una subclase, no tocar esta clase.
```php
namespace App\Exports\Pdf;

use App\Contracts\Exports\ExporterInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

abstract class BasePdfExporter implements ExporterInterface
{
    /**
     * Título que aparece en el encabezado del PDF.
     */
    abstract protected function title(): string;

    /**
     * Definición de todas las columnas posibles para este tipo de exportación.
     * Formato: ['clave' => 'Etiqueta visible']
     */
    abstract protected function allColumnDefinitions(): array;

    /**
     * Obtiene los datos ya filtrados del repositorio correspondiente.
     */
    abstract protected function fetchData(array $filters): Collection;

    /**
     * Mapea un registro del modelo a un array de valores escalares,
     * respetando el orden de $activeKeys.
     *
     * @param  mixed    $record
     * @param  string[] $activeKeys  Claves de las columnas seleccionadas
     * @return string[]
     */
    abstract protected function mapRow(mixed $record, array $activeKeys): array;

    /**
     * Vista Blade a renderizar. Sobreescribir en la subclase para usar
     * una plantilla personalizada en lugar de la genérica.
     */
    protected function view(): string
    {
        return 'exports.generic';
    }

    protected function paperSize(): string        { return 'a4'; }
    protected function paperOrientation(): string { return 'landscape'; }

    final public function export(array $filters, array $columns, string $filePath): string
    {
        $data = $this->fetchData($filters);

        $allDefs = $this->allColumnDefinitions();
        $activeColumns = empty($columns)
            ? $allDefs
            : array_intersect_key($allDefs, array_flip($columns));

        $rows = $data
            ->map(fn($record) => $this->mapRow($record, array_keys($activeColumns)))
            ->all();

        $pdf = Pdf::loadView($this->view(), [
            'title'         => $this->title(),
            'activeColumns' => $activeColumns,   // ['clave' => 'Etiqueta']
            'rows'          => $rows,            // array de arrays de strings
            'total'         => count($rows),
        ])->setPaper($this->paperSize(), $this->paperOrientation());

        Storage::disk('local')->put($filePath, $pdf->output());

        return $filePath;
    }

    final public function getExtension(): string { return 'pdf'; }
    final public function getMimeType(): string  { return 'application/pdf'; }
}
```
**Por qué `final` en `export()`, `getExtension()` y `getMimeType()`:** evita que subclases rompan el contrato accidentalmente. Solo se puede personalizar mediante los métodos abstractos/protegidos.

---

#### `back/resources/views/exports/generic.blade.php`
**Propósito:** Vista Blade genérica para todos los PDFs del sistema. Recibe datos ya mapeados a strings (`$rows`), no modelos Eloquent. CSS inline compatible con DomPDF; usa `DejaVu Sans` para UTF-8.
```blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body  { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1    { font-size: 14px; margin-bottom: 2px; }
        .meta { color: #6b7280; font-size: 9px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th    { background-color: #374151; color: #ffffff; padding: 6px 8px; text-align: left; font-size: 9px; }
        td    { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
        tr:nth-child(even) td { background-color: #f9fafb; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <p class="meta">
        Generado el {{ now()->format('d/m/Y H:i') }}
        &nbsp;·&nbsp;
        {{ $total }} {{ $total === 1 ? 'registro' : 'registros' }}
    </p>

    <table>
        <thead>
            <tr>
                @foreach($activeColumns as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr>
                @foreach($row as $cell)
                    <td>{{ $cell }}</td>
                @endforeach
            </tr>
            @empty
            <tr>
                <td colspan="{{ count($activeColumns) }}" style="text-align:center;color:#6b7280;">
                    Sin registros
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
```
**Variables que recibe:**
| Variable | Tipo | Descripción |
|---|---|---|
| `$title` | `string` | Encabezado del PDF (ej: "Listado de Usuarios") |
| `$activeColumns` | `array<string,string>` | `['clave' => 'Etiqueta']` de columnas seleccionadas |
| `$rows` | `array<array<string>>` | Filas ya mapeadas a strings, en el mismo orden que `$activeColumns` |
| `$total` | `int` | Cantidad de registros |

**Vista personalizada por módulo:** si un módulo necesita una plantilla especial (firma, logo, agrupaciones), crea `resources/views/exports/{modulo}.blade.php` y sobreescribe `view()` en su exporter. La vista personalizada puede recibir las mismas variables o agregar más sobreescribiendo `export()` (aunque esto rompe el contrato `final` — en ese caso el exporter debe no extender `BasePdfExporter` sino implementar `ExporterInterface` directamente).

---

#### `back/app/Exports/Users/UsersPdfExporter.php`
**Propósito:** Exporter PDF para el módulo de usuarios. Extiende `BasePdfExporter`; solo implementa el mapeo específico del modelo `User`. No escribe nada de lógica PDF.
```php
namespace App\Exports\Users;

use App\Contracts\Repositories\UserRepositoryInterface;
use App\Exports\Pdf\BasePdfExporter;
use Illuminate\Support\Collection;

class UsersPdfExporter extends BasePdfExporter
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    protected function title(): string
    {
        return 'Listado de Usuarios';
    }

    protected function allColumnDefinitions(): array
    {
        return [
            'guid'          => 'ID',
            'first_name'    => 'Nombre',
            'last_name'     => 'Apellido',
            'email'         => 'Email',
            'status'        => 'Estado',
            'last_login_at' => 'Último login',
            'created_at'    => 'Fecha de creación',
        ];
    }

    protected function fetchData(array $filters): Collection
    {
        return $this->userRepository->exportQuery($filters)->get();
    }

    protected function mapRow(mixed $user, array $activeKeys): array
    {
        $all = [
            'guid'          => $user->guid,
            'first_name'    => $user->first_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'status'        => $user->locked_at
                                ? 'Bloqueado'
                                : ($user->email_verified_at ? 'Verificado' : 'No verificado'),
            'last_login_at' => $user->last_login_at?->format('d/m/Y H:i') ?? '-',
            'created_at'    => $user->created_at?->format('d/m/Y H:i') ?? '-',
        ];

        return array_map(fn(string $key) => $all[$key] ?? '-', $activeKeys);
    }

    // view() no se sobreescribe → usa 'exports.generic' por defecto.
    // Para una plantilla personalizada, descomentar:
    // protected function view(): string { return 'exports.users'; }
}
```
**Dependencias inyectadas:** `UserRepositoryInterface` (solo para obtener datos; la lógica PDF está en `BasePdfExporter`).

**Ejemplo de exporter de Roles con el mismo patrón** (para cuando se implemente el módulo):
```php
// App\Exports\Roles\RolesPdfExporter — solo cambian title(), allColumnDefinitions(), fetchData(), mapRow()
protected function title(): string { return 'Listado de Roles'; }

protected function allColumnDefinitions(): array
{
    return [
        'guid'              => 'ID',
        'name'              => 'Nombre',
        'permissions_count' => 'Cantidad de permisos',
        'permissions'       => 'Permisos',
        'created_at'        => 'Fecha de creación',
    ];
}

protected function fetchData(array $filters): Collection
{
    return $this->roleRepository->exportQuery($filters)->get();
}

protected function mapRow(mixed $role, array $activeKeys): array
{
    $all = [
        'guid'              => $role->guid,
        'name'              => $role->name,
        'permissions_count' => (string) $role->permissions->count(),
        'permissions'       => $role->permissions->pluck('name')->join(', '),
        'created_at'        => $role->created_at?->format('d/m/Y H:i') ?? '-',
    ];
    return array_map(fn(string $key) => $all[$key] ?? '-', $activeKeys);
}
// Sin vista personalizada → usa 'exports.generic'
```

---

#### `back/app/Services/Exports/ExportResolverService.php`
**Propósito:** Implementa `ExportResolverInterface`. Mapea (tipo, formato) → ExporterInterface. Es el único punto a modificar para registrar nuevos formatos/tipos.
```php
namespace App\Services\Exports;

use App\Contracts\Exports\ExportResolverInterface;
use App\Contracts\Exports\ExporterInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\ExportFormat;
use App\Enums\ExportType;
use App\Exports\Users\UsersExporter;
use App\Exports\Users\UsersPdfExporter;
use App\Exports\Users\UsersTxtExporter;

class ExportResolverService implements ExportResolverInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
    ) {}

    public function resolve(string $exportType, string $format): ExporterInterface
    {
        $typeEnum   = ExportType::from($exportType);     // lanza ValueError si inválido
        $formatEnum = ExportFormat::from($format);       // lanza ValueError si inválido

        return match(true) {
            $typeEnum === ExportType::USERS && in_array($formatEnum, [ExportFormat::XLSX, ExportFormat::CSV])
                => new UsersExporter($this->userRepository, $formatEnum),

            $typeEnum === ExportType::USERS && $formatEnum === ExportFormat::TXT
                => new UsersTxtExporter($this->userRepository),

            $typeEnum === ExportType::USERS && $formatEnum === ExportFormat::PDF
                => new UsersPdfExporter($this->userRepository),

            default => throw new \InvalidArgumentException(
                "Combinación de tipo '{$exportType}' y formato '{$format}' no soportada."
            ),
        };
    }

    public function availableFormats(string $exportType): array
    {
        return match(ExportType::from($exportType)) {
            ExportType::USERS => [ExportFormat::XLSX->value, ExportFormat::CSV->value, ExportFormat::TXT->value, ExportFormat::PDF->value],
        };
    }
}
```
**Dependencias inyectadas:** `UserRepositoryInterface` (y las de futuros tipos al registrarlos).

---

#### `back/app/Services/Exports/ExportService.php`
**Propósito:** Orquesta el ciclo de vida de una exportación: crea el registro en BD, llama al exporter síncrono o despacha el Job asíncrono.
```php
namespace App\Services\Exports;

use App\Contracts\Exports\ExportResolverInterface;
use App\Contracts\Repositories\ExportRepositoryInterface;
use App\Enums\ExportStatus;
use App\Jobs\ProcessExportJob;
use App\Models\Export;
use App\Models\User;
use Illuminate\Support\Str;

class ExportService
{
    public function __construct(
        private readonly ExportRepositoryInterface $exportRepository,
        private readonly ExportResolverInterface   $exportResolver,
    ) {}

    /**
     * Crea el registro de exportación y decide si es síncrona o asíncrona.
     *
     * Para síncrona: ejecuta el export inmediatamente y retorna el Export completado.
     * Para asíncrona: despacha el Job y retorna el Export en estado pending.
     */
    public function initiate(
        User   $user,
        string $exportType,
        string $format,
        array  $filters  = [],
        array  $columns  = [],
        bool   $async    = false,
    ): Export {
        $filePath = $this->buildFilePath($user->guid, $format);
        $fileName = $this->buildFileName($exportType, $format);

        $export = $this->exportRepository->create([
            'user_id'    => $user->id,
            'type'       => $exportType,
            'format'     => $format,
            'status'     => ExportStatus::PENDING,
            'file_path'  => $filePath,
            'file_name'  => $fileName,
            'filters'    => $filters,
            'columns'    => $columns,
            'expires_at' => now()->addDays(7),
        ]);

        if ($async) {
            ProcessExportJob::dispatch($export->id);
            return $export;
        }

        // Síncrono: ejecutar directamente
        return $this->process($export);
    }

    /**
     * Ejecuta la exportación (usado por el Job y por el modo síncrono).
     */
    public function process(Export $export): Export
    {
        $this->exportRepository->updateStatus($export, ExportStatus::PROCESSING);

        try {
            $exporter = $this->exportResolver->resolve(
                $export->type->value,
                $export->format->value,
            );

            $exporter->export(
                $export->filters ?? [],
                $export->columns ?? [],
                $export->file_path,
            );

            return $this->exportRepository->updateStatus(
                $export,
                ExportStatus::COMPLETED,
            );
        } catch (\Throwable $e) {
            $this->exportRepository->updateStatus(
                $export,
                ExportStatus::FAILED,
                ['error_message' => $e->getMessage()],
            );

            throw $e;
        }
    }

    private function buildFilePath(string $userGuid, string $format): string
    {
        $yearMonth = now()->format('Y-m');
        $uuid      = Str::uuid()->toString();
        return "exports/{$userGuid}/{$yearMonth}/{$uuid}.{$format}";
    }

    private function buildFileName(string $exportType, string $format): string
    {
        return "{$exportType}_" . now()->format('Ymd_His') . ".{$format}";
    }
}
```
**Dependencias inyectadas:** `ExportRepositoryInterface`, `ExportResolverInterface`.

---

#### `back/app/Jobs/ProcessExportJob.php`
**Propósito:** Job para procesar exportaciones en background (modo asíncrono).
```php
namespace App\Jobs;

use App\Events\ExportCompletedEvent;
use App\Events\ExportFailedEvent;
use App\Models\Export;
use App\Services\Exports\ExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessExportJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 300; // 5 minutos máximo

    public function __construct(
        public readonly int $exportId,
    ) {}

    public function handle(ExportService $exportService): void
    {
        $export = Export::findOrFail($this->exportId);

        try {
            $completed = $exportService->process($export);
            event(new ExportCompletedEvent($completed));
        } catch (\Throwable $e) {
            // El ExportService ya actualizó el status a FAILED.
            // Solo disparamos el evento de fallo.
            event(new ExportFailedEvent($export->fresh(), $e->getMessage()));
            $this->fail($e);
        }
    }

    public function failed(\Throwable $exception): void
    {
        // Loguear si todos los reintentos se agotaron sin haber disparado el evento
        \Illuminate\Support\Facades\Log::error("ProcessExportJob falló definitivamente", [
            'export_id' => $this->exportId,
            'error'     => $exception->getMessage(),
        ]);
    }
}
```

---

#### `back/app/Events/ExportCompletedEvent.php`
**Propósito:** Evento disparado cuando una exportación asíncrona finaliza exitosamente.
```php
namespace App\Events;

use App\Models\Export;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExportCompletedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Export $export,
    ) {}
}
```

---

#### `back/app/Events/ExportFailedEvent.php`
**Propósito:** Evento disparado cuando una exportación asíncrona falla.
```php
namespace App\Events;

use App\Models\Export;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExportFailedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Export $export,
        public readonly string $errorMessage,
    ) {}
}
```

---

#### `back/app/Listeners/NotifyExportCompletedListener.php`
**Propósito:** Escucha `ExportCompletedEvent` y envía notificación al usuario via canal `database`.
```php
namespace App\Listeners;

use App\Events\ExportCompletedEvent;
use App\Notifications\ExportReadyNotification;

class NotifyExportCompletedListener
{
    public function handle(ExportCompletedEvent $event): void
    {
        $event->export->user->notify(new ExportReadyNotification($event->export));
    }
}
```

---

#### `back/app/Listeners/NotifyExportFailedListener.php`
**Propósito:** Escucha `ExportFailedEvent` y envía notificación de fallo al usuario.
```php
namespace App\Listeners;

use App\Events\ExportFailedEvent;
use App\Notifications\ExportFailedNotification;

class NotifyExportFailedListener
{
    public function handle(ExportFailedEvent $event): void
    {
        $event->export->user->notify(new ExportFailedNotification($event->export));
    }
}
```

---

#### `back/app/Notifications/ExportReadyNotification.php`
**Propósito:** Notificación de exportación lista, almacenada en canal `database`.
```php
namespace App\Notifications;

use App\Models\Export;
use Illuminate\Notifications\Notification;

class ExportReadyNotification extends Notification
{
    public function __construct(
        private readonly Export $export,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'        => 'export_ready',
            'export_guid' => $this->export->guid,
            'file_name'   => $this->export->file_name,
            'format'      => $this->export->format->value,
            'export_type' => $this->export->type->value,
            'message'     => "Tu exportación de {$this->export->type->label()} está lista para descargar.",
        ];
    }
}
```

---

#### `back/app/Notifications/ExportFailedNotification.php`
**Propósito:** Notificación de exportación fallida.
```php
namespace App\Notifications;

use App\Models\Export;
use Illuminate\Notifications\Notification;

class ExportFailedNotification extends Notification
{
    public function __construct(
        private readonly Export $export,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type'        => 'export_failed',
            'export_guid' => $this->export->guid,
            'export_type' => $this->export->type->value,
            'message'     => "Hubo un error al generar tu exportación de {$this->export->type->label()}.",
        ];
    }
}
```

---

#### `back/app/Policies/ExportPolicy.php`
**Propósito:** Autorización de acciones sobre exportaciones.
```php
namespace App\Policies;

use App\Models\Export;
use App\Models\User;

class ExportPolicy
{
    /**
     * Si puede iniciar una exportación (cualquier tipo).
     */
    public function create(User $user, string $exportType): bool
    {
        return $user->hasPermissionTo('exports.alta');
    }

    /**
     * Si puede ver el estado de una exportación y descargarla.
     * Solo el dueño puede descargar su propia exportación.
     */
    public function download(User $user, Export $export): bool
    {
        return $user->id === $export->user_id;
    }

    /**
     * Si puede ver el listado de sus exportaciones.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('exports.alta');
    }
}
```

---

#### `back/app/Http/Requests/Exports/InitiateExportRequest.php`
**Propósito:** Valida el request de inicio de exportación.
```php
namespace App\Http\Requests\Exports;

use App\Enums\ExportFormat;
use App\Enums\ExportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class InitiateExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La policy se aplica en el controller
    }

    public function rules(): array
    {
        return [
            'type'    => ['required', 'string', new Enum(ExportType::class)],
            'format'  => ['required', 'string', new Enum(ExportFormat::class)],
            'async'   => ['sometimes', 'boolean'],
            'filters' => ['sometimes', 'array'],
            'filters.search'    => ['sometimes', 'string', 'max:100'],
            'filters.status'    => ['sometimes', 'string', 'in:verified,unverified,locked'],
            'filters.date_from' => ['sometimes', 'date_format:Y-m-d'],
            'filters.date_to'   => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:filters.date_from'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['string'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required'    => 'El tipo de exportación es requerido.',
            'type.Illuminate\Validation\Rules\Enum' => 'El tipo de exportación no es válido.',
            'format.required'  => 'El formato de exportación es requerido.',
            'format.Illuminate\Validation\Rules\Enum' => 'El formato de exportación no es válido.',
            'filters.date_to.after_or_equal' => 'La fecha final debe ser posterior a la inicial.',
        ];
    }
}
```

---

#### `back/app/Http/Resources/V1/ExportResource.php`
**Propósito:** API Resource para serializar un Export.
```php
namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'guid'          => $this->guid,
            'type'          => $this->type->value,
            'type_label'    => $this->type->label(),
            'format'        => $this->format->value,
            'status'        => $this->status->value,
            'file_name'     => $this->file_name,
            'is_downloadable' => $this->isDownloadable(),
            'error_message' => $this->error_message,
            'expires_at'    => $this->expires_at?->toISOString(),
            'created_at'    => $this->created_at?->toISOString(),
        ];
    }
}
```

---

#### `back/app/Http/Controllers/V1/ExportController.php`
**Propósito:** Controlador delgado para el módulo de exportaciones. Sigue el patrón del proyecto.
```php
namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exports\InitiateExportRequest;
use App\Http\Resources\V1\ExportResource;
use App\Models\Export;
use App\Repositories\ExportRepositoryEloquent;
use App\Services\Exports\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExportController extends Controller
{
    public function __construct(
        private readonly ExportService              $exportService,
        private readonly ExportRepositoryEloquent   $exportRepository,
    ) {}

    /**
     * POST /v1/exports
     * Inicia una exportación (síncrona o asíncrona).
     */
    public function store(InitiateExportRequest $request): JsonResponse
    {
        try {
            $this->authorize('create', [Export::class, $request->validated('type')]);

            $export = $this->exportService->initiate(
                user:       $request->user(),
                exportType: $request->validated('type'),
                format:     $request->validated('format'),
                filters:    $request->validated('filters', []),
                columns:    $request->validated('columns', []),
                async:      (bool) $request->validated('async', false),
            );

            $code = $request->validated('async', false) ? 202 : 200;

            return $this->makeSuccess(new ExportResource($export), 'Exportación iniciada.', $code);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/exports
     * Lista las exportaciones del usuario autenticado.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $this->authorize('viewAny', Export::class);

            $perPage  = (int) $request->get('per_page', 15);
            $paginator = $this->exportRepository->listForUser($request->user(), $perPage);

            return $this->makeSuccess([
                'data'         => ExportResource::collection($paginator->items()),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ]);
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/exports/{guid}
     * Detalle de una exportación.
     */
    public function show(string $guid): JsonResponse
    {
        try {
            $export = $this->exportRepository->findByGuid($guid);

            if (!$export) {
                return $this->makeNotFound('Exportación no encontrada.');
            }

            $this->authorize('download', $export);

            return $this->makeSuccess(new ExportResource($export));
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }

    /**
     * GET /v1/exports/{guid}/download
     * Descarga el archivo de una exportación completada.
     * Retorna el binario del archivo (no JSON).
     */
    public function download(string $guid): Response|JsonResponse
    {
        try {
            $export = $this->exportRepository->findByGuid($guid);

            if (!$export) {
                return $this->makeNotFound('Exportación no encontrada.');
            }

            $this->authorize('download', $export);

            if (!$export->isDownloadable()) {
                return $this->makeError(null, 'La exportación no está disponible para descarga.', 422);
            }

            return response()->download(
                storage_path('app/private/' . $export->file_path),
                $export->file_name,
                ['Content-Type' => $export->format->mimeType()],
            );
        } catch (\Exception $e) {
            return $this->makeFromException($e);
        }
    }
}
```

---

#### `back/app/Console/Commands/ExportsCleanupCommand.php`
**Propósito:** Comando Artisan para limpiar exportaciones expiradas y sus archivos.
```php
namespace App\Console\Commands;

use App\Contracts\Repositories\ExportRepositoryInterface;
use Illuminate\Console\Command;

class ExportsCleanupCommand extends Command
{
    protected $signature   = 'exports:cleanup';
    protected $description = 'Elimina exportaciones expiradas y sus archivos del disco.';

    public function handle(ExportRepositoryInterface $exportRepository): int
    {
        $deleted = $exportRepository->deleteExpired();
        $this->info("Se eliminaron {$deleted} exportaciones expiradas.");
        return Command::SUCCESS;
    }
}
```

---

### Archivos a modificar

#### `back/app/Contracts/Repositories/UserRepositoryInterface.php`
**Cambio:** Agregar método `exportQuery()` que retorna un QueryBuilder sin paginar (para exportaciones).
**Antes:** Sin método `exportQuery`.
**Después:** Agregar:
```php
use Illuminate\Database\Eloquent\Builder;
// ...
public function exportQuery(array $filters): Builder;
```

---

#### `back/app/Repositories/UserRepositoryEloquent.php`
**Cambio:** Implementar `exportQuery()` — reutiliza la lógica de filtros de `list()` pero sin paginar.
**Antes:** Sin método `exportQuery`.
**Después:** Agregar:
```php
public function exportQuery(array $filters): Builder
{
    $query = $this->newQuery()->orderBy('created_at', 'desc');

    if (!empty($filters['search'])) {
        $search = $filters['search'];
        $query->where(function ($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        });
    }

    if (!empty($filters['status'])) {
        match($filters['status']) {
            'verified'   => $query->whereNotNull('email_verified_at')->whereNull('locked_at'),
            'unverified' => $query->whereNull('email_verified_at'),
            'locked'     => $query->whereNotNull('locked_at'),
            default      => null,
        };
    }

    if (!empty($filters['date_from'])) {
        $query->whereDate('created_at', '>=', $filters['date_from']);
    }

    if (!empty($filters['date_to'])) {
        $query->whereDate('created_at', '<=', $filters['date_to']);
    }

    return $query;
}
```
Nota: La lógica es idéntica a `list()` pero sin `->paginate()`. Si en el futuro se refactoriza `list()` para llamar `exportQuery()->paginate()`, es un refactor limpio.

---

#### `back/app/Providers/AppServiceProvider.php`
**Cambio:** Agregar bindings de los nuevos contratos.
**Antes:** Solo bindea User y Role repositories.
**Después:** Agregar en `register()`:
```php
use App\Contracts\Exports\ExportResolverInterface;
use App\Contracts\Repositories\ExportRepositoryInterface;
use App\Repositories\ExportRepositoryEloquent;
use App\Services\Exports\ExportResolverService;

// En register():
$this->app->bind(ExportRepositoryInterface::class, ExportRepositoryEloquent::class);
$this->app->bind(ExportResolverInterface::class, ExportResolverService::class);
```

---

#### `back/app/Providers/EventServiceProvider.php`
**Cambio:** Este archivo no existe aún en el proyecto (Laravel 12 usa `AppServiceProvider` para todo). Registrar los listeners dentro de `AppServiceProvider::boot()`.
**Después:** En `boot()` de `AppServiceProvider`:
```php
use App\Events\ExportCompletedEvent;
use App\Events\ExportFailedEvent;
use App\Listeners\NotifyExportCompletedListener;
use App\Listeners\NotifyExportFailedListener;
use Illuminate\Support\Facades\Event;

// En boot():
Event::listen(ExportCompletedEvent::class, NotifyExportCompletedListener::class);
Event::listen(ExportFailedEvent::class, NotifyExportFailedListener::class);
```

---

#### `back/app/Providers/AuthServiceProvider.php` (crear si no existe) O en `AppServiceProvider::boot()`
**Cambio:** Registrar `ExportPolicy`. En Laravel 12 se puede usar el método `Gate::policy()` en `AppServiceProvider::boot()`.
```php
use App\Models\Export;
use App\Policies\ExportPolicy;
use Illuminate\Support\Facades\Gate;

// En boot():
Gate::policy(Export::class, ExportPolicy::class);
```

---

#### `back/database/seeders/PermissionSeeder.php`
**Cambio:** Agregar permiso `exports.alta`.
**Antes:** Lista de 8 permisos (users.* y roles.*).
**Después:** Agregar `'exports.alta'` al array `$permissions`.

---

#### `back/database/seeders/RoleSeeder.php`
**Cambio:** Asignar `exports.alta` a los roles `super-admin` y `admin`.
**Antes:** `super-admin` recibe todos los permisos (automáticamente incluirá el nuevo). `admin` tiene lista explícita.
**Después:** Para `super-admin` no cambia nada (recibe `Permission::all()`). Para `admin`, agregar `'exports.alta'` al array de su `syncPermissions`.

---

#### `back/routes/api/users.php`
**Cambio:** Ninguno. Las rutas de export van en un archivo separado.

---

### Migrations

#### `back/database/migrations/2026_05_18_000001_create_exports_table.php`
```php
Schema::create('exports', function (Blueprint $table) {
    $table->id();
    $table->uuid('guid')->unique()->index();
    $table->foreignId('user_id')
          ->constrained('users')
          ->cascadeOnDelete();
    $table->string('type', 50);       // ExportType enum value: 'users'
    $table->string('format', 10);     // ExportFormat enum value: 'xlsx', 'csv', etc.
    $table->string('status', 20)->default('pending'); // ExportStatus enum value
    $table->string('file_path', 500)->nullable();
    $table->string('file_name', 255)->nullable();
    $table->json('filters')->nullable();
    $table->json('columns')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();

    $table->index('user_id');
    $table->index('status');
    $table->index('expires_at');
});
```
Es reversible con `Schema::dropIfExists('exports')`.

---

#### `back/database/migrations/2026_05_18_000002_create_notifications_table.php`
Crear con el comando de Laravel (no escribir a mano):
```bash
php artisan notifications:table
php artisan migrate
```
Genera la tabla `notifications` estándar de Laravel con: `id (uuid PK)`, `type`, `notifiable_type`, `notifiable_id`, `data (text)`, `read_at`, `created_at`, `updated_at`.

---

### Rutas API

Crear archivo `back/routes/api/exports.php`:

| Método | URI                          | Controller@Method           | Middleware     | Notas                          |
|--------|------------------------------|-----------------------------|----------------|--------------------------------|
| POST   | /v1/exports                  | ExportController@store      | auth:sanctum   | Inicia exportación             |
| GET    | /v1/exports                  | ExportController@index      | auth:sanctum   | Lista exportaciones del user   |
| GET    | /v1/exports/{guid}           | ExportController@show       | auth:sanctum   | Detalle de exportación         |
| GET    | /v1/exports/{guid}/download  | ExportController@download   | auth:sanctum   | Descarga el archivo binario    |

```php
// back/routes/api/exports.php
use App\Http\Controllers\V1\ExportController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/exports')->middleware('auth:sanctum')->group(function () {
    Route::get('/',                    [ExportController::class, 'index']);
    Route::post('/',                   [ExportController::class, 'store']);
    Route::get('/{guid}',              [ExportController::class, 'show']);
    Route::get('/{guid}/download',     [ExportController::class, 'download']);
});
```

---

### Permisos Spatie

| Nombre         | Seeder donde se agrega | Roles que lo reciben              |
|----------------|------------------------|-----------------------------------|
| `exports.alta` | `PermissionSeeder`     | `super-admin` (automático), `admin` |

Nota: El rol `operador` NO recibe `exports.alta` por defecto — si el negocio lo requiere, se agrega en una iteración futura.

---

### Contrato de los endpoints

**POST /v1/exports** (inicio de exportación)

Request:
```json
{
  "type": "users",
  "format": "xlsx",
  "async": false,
  "filters": {
    "search": "juan",
    "status": "verified",
    "date_from": "2026-01-01",
    "date_to": "2026-05-18"
  },
  "columns": ["guid", "first_name", "last_name", "email", "status"]
}
```

Response 200 (síncrono, completado):
```json
{
  "success": true,
  "data": {
    "guid": "550e8400-e29b-41d4-a716-446655440000",
    "type": "users",
    "type_label": "Usuarios",
    "format": "xlsx",
    "status": "completed",
    "file_name": "users_20260518_143022.xlsx",
    "is_downloadable": true,
    "error_message": null,
    "expires_at": "2026-05-25T14:30:22.000000Z",
    "created_at": "2026-05-18T14:30:22.000000Z"
  },
  "message": "Exportación iniciada."
}
```

Response 202 (asíncrono, en cola):
```json
{
  "success": true,
  "data": {
    "guid": "550e8400-...",
    "type": "users",
    "format": "xlsx",
    "status": "pending",
    "is_downloadable": false,
    ...
  },
  "message": "Exportación iniciada."
}
```

Errores posibles:

| HTTP | Situación                                             |
|------|-------------------------------------------------------|
| 403  | Usuario sin permiso `exports.alta`                    |
| 422  | Tipo o formato inválido, filtros con formato incorrecto |
| 500  | Error interno del servidor                            |

**GET /v1/exports/{guid}/download**

Response 200: binario del archivo (`Content-Disposition: attachment`). NO es JSON.

Errores posibles:

| HTTP | Situación                                             |
|------|-------------------------------------------------------|
| 403  | El export no pertenece al usuario autenticado         |
| 404  | Export no encontrado                                  |
| 422  | Export no completado o expirado                       |

---

### Tests a generar (qué cubrir, no el código)

**Feature tests — ExportController:**
- `POST /v1/exports` sin autenticación → 401.
- `POST /v1/exports` con usuario sin permiso `exports.alta` → 403.
- `POST /v1/exports` con tipo inválido → 422.
- `POST /v1/exports` con formato inválido → 422.
- `POST /v1/exports` síncrono con datos válidos → 200, status=completed, archivo generado en storage.
- `POST /v1/exports` asíncrono con datos válidos → 202, status=pending, Job en cola.
- `GET /v1/exports` → lista solo las exportaciones del usuario autenticado.
- `GET /v1/exports/{guid}` de un export ajeno → 403.
- `GET /v1/exports/{guid}/download` de export completado → archivo descargado.
- `GET /v1/exports/{guid}/download` de export pending → 422.
- `GET /v1/exports/{guid}/download` de export expirado → 422.

**Unit tests — ExportService:**
- `initiate()` con `async=false` → llama `process()` directamente, retorna Export con status=completed.
- `initiate()` con `async=true` → despacha `ProcessExportJob`, retorna Export con status=pending.
- `process()` cuando el exporter lanza excepción → status=failed, error_message seteado.

**Unit tests — ExportResolverService:**
- `resolve('users', 'xlsx')` → instancia de `UsersExporter`.
- `resolve('users', 'csv')` → instancia de `UsersExporter` con formato CSV.
- `resolve('users', 'txt')` → instancia de `UsersTxtExporter`.
- `resolve('users', 'pdf')` → instancia de `UsersPdfExporter`.
- `resolve('inventado', 'xlsx')` → lanza `ValueError`.

**Unit tests — ExportPolicy:**
- Usuario con `exports.alta` puede `create`.
- Usuario sin `exports.alta` no puede `create`.
- Usuario puede `download` su propio export.
- Usuario no puede `download` el export de otro.

---

## Cambios en FRONTEND

### Archivos a crear

#### `front/src/modules/exports/types/export.types.ts`
```typescript
export type ExportStatus = 'pending' | 'processing' | 'completed' | 'failed'
export type ExportFormat = 'xlsx' | 'csv' | 'txt' | 'pdf'
export type ExportType   = 'users'

export interface ExportItem {
  guid:            string
  type:            ExportType
  type_label:      string
  format:          ExportFormat
  status:          ExportStatus
  file_name:       string | null
  is_downloadable: boolean
  error_message:   string | null
  expires_at:      string | null
  created_at:      string
}

export interface InitiateExportPayload {
  type:     ExportType
  format:   ExportFormat
  async?:   boolean
  filters?: Record<string, string | undefined>
  columns?: string[]
}

export interface ExportListResponse {
  data:         ExportItem[]
  current_page: number
  last_page:    number
  per_page:     number
  total:        number
}
```

---

#### `front/src/modules/exports/api/exports.api.ts`
```typescript
import { http } from '@/core/api/http'
import type { ExportItem, ExportListResponse, InitiateExportPayload } from '../types/export.types'

export async function initiateExportApi(payload: InitiateExportPayload): Promise<ExportItem> {
  const response = await http.post<ExportItem>('/v1/exports', payload)
  return response.data
}

export async function listExportsApi(params?: { per_page?: number; page?: number }): Promise<ExportListResponse> {
  const response = await http.get<ExportListResponse>('/v1/exports', { params })
  return response.data
}

export async function getExportApi(guid: string): Promise<ExportItem> {
  const response = await http.get<ExportItem>(`/v1/exports/${guid}`)
  return response.data
}

/**
 * Descarga el archivo. Como el endpoint retorna binario (no JSON),
 * se usa responseType: 'blob' y se omite el interceptor de desenvuelto.
 * Se maneja la creación del link de descarga en el composable.
 */
export async function downloadExportApi(guid: string, fileName: string): Promise<void> {
  const response = await http.get(`/v1/exports/${guid}/download`, {
    responseType: 'blob',
  })
  const url  = window.URL.createObjectURL(new Blob([response.data]))
  const link = document.createElement('a')
  link.href  = url
  link.setAttribute('download', fileName)
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.URL.revokeObjectURL(url)
}
```

**Nota crítica:** El interceptor de `http.ts` desenvuelve el wrapper `{success, data}` solo cuando `response.data.success === true`. Para respuestas `blob` ese campo no existe, por lo que el interceptor pasa la response sin modificar. El `response.data` será directamente el `Blob`. Esto funciona correctamente sin modificar el interceptor.

---

#### `front/src/modules/exports/composables/useInitiateExport.ts`
```typescript
import { useMutation, useQueryClient } from '@tanstack/vue-query'
import { initiateExportApi } from '../api/exports.api'
import { downloadExportApi } from '../api/exports.api'
import { useNotification } from '@/core/composables/useNotification'
import type { InitiateExportPayload, ExportItem } from '../types/export.types'

export function useInitiateExport() {
  const queryClient = useQueryClient()
  const { success, error, info } = useNotification()

  return useMutation({
    mutationFn: (payload: InitiateExportPayload) => initiateExportApi(payload),
    onSuccess: async (exportItem: ExportItem) => {
      queryClient.invalidateQueries({ queryKey: ['exports'] })

      if (exportItem.status === 'completed' && exportItem.is_downloadable) {
        // Síncrono: descargar inmediatamente
        await downloadExportApi(exportItem.guid, exportItem.file_name!)
        success('Exportación completada. Descargando...')
      } else {
        // Asíncrono: notificar que se está procesando
        info('Exportación en proceso. Te notificaremos cuando esté lista.')
      }
    },
    onError: () => error('Error al iniciar la exportación.'),
  })
}
```

---

#### `front/src/modules/exports/composables/useExports.ts`
```typescript
import { useQuery } from '@tanstack/vue-query'
import { listExportsApi } from '../api/exports.api'

export function useExports(params?: { per_page?: number }) {
  return useQuery({
    queryKey: ['exports', params],
    queryFn:  () => listExportsApi(params),
    refetchInterval: (query) => {
      // Si hay alguna exportación en proceso, hacer polling cada 10 segundos
      const data = query.state.data
      const hasProcessing = data?.data?.some(
        e => e.status === 'pending' || e.status === 'processing'
      )
      return hasProcessing ? 10_000 : false
    },
  })
}
```

---

#### `front/src/modules/exports/stores/exports-ui.store.ts`
```typescript
import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useExportsUiStore = defineStore('exports-ui', () => {
  // Estado del modal de exportación (tipo, columnas disponibles, filtros activos)
  const isModalOpen        = ref(false)
  const pendingExportType  = ref<string | null>(null)
  const pendingFilters     = ref<Record<string, string | undefined>>({})
  const availableColumns   = ref<{ key: string; label: string }[]>([])
  const selectedColumns    = ref<string[]>([])

  function openExportModal(options: {
    exportType:       string
    filters:          Record<string, string | undefined>
    availableColumns: { key: string; label: string }[]
  }) {
    pendingExportType.value  = options.exportType
    pendingFilters.value     = options.filters
    availableColumns.value   = options.availableColumns
    selectedColumns.value    = options.availableColumns.map(c => c.key) // todas por defecto
    isModalOpen.value        = true
  }

  function closeExportModal() {
    isModalOpen.value = false
  }

  return {
    isModalOpen,
    pendingExportType,
    pendingFilters,
    availableColumns,
    selectedColumns,
    openExportModal,
    closeExportModal,
  }
})
```

---

#### `front/src/modules/exports/components/ExportButton.vue`
**Propósito:** Botón reutilizable que abre el modal de exportación. Se puede usar en cualquier listado del sistema.
```vue
<template>
  <PermissionGuard permission="exports.alta">
    <a-button
      :loading="mutation.isPending.value"
      @click="handleExport"
    >
      <template #icon><download-outlined /></template>
      Exportar
    </a-button>
  </PermissionGuard>
</template>

<script setup lang="ts">
import { DownloadOutlined } from '@ant-design/icons-vue'
import PermissionGuard from '@/components/shared/PermissionGuard.vue'
import { useExportsUiStore } from '../stores/exports-ui.store'
import { useInitiateExport } from '../composables/useInitiateExport'
import type { ExportType } from '../types/export.types'

const props = defineProps<{
  exportType:       ExportType
  filters?:         Record<string, string | undefined>
  availableColumns: { key: string; label: string }[]
}>()

const exportsUiStore = useExportsUiStore()
const mutation       = useInitiateExport()

function handleExport() {
  exportsUiStore.openExportModal({
    exportType:       props.exportType,
    filters:          props.filters ?? {},
    availableColumns: props.availableColumns,
  })
}
</script>
```

---

#### `front/src/modules/exports/components/ExportModal.vue`
**Propósito:** Modal global de configuración de exportación. Se monta una sola vez en el layout. Consume el store `exports-ui` y el composable `useExportFormat` existente.
```vue
<template>
  <BaseModal
    v-model="exportsUiStore.isModalOpen"
    title="Exportar datos"
    :width="560"
    @cancel="exportsUiStore.closeExportModal"
  >
    <!-- Selección de formato -->
    <div class="mb-4">
      <label class="block mb-1 font-medium">Formato</label>
      <BaseSelect
        v-model="selectedFormat"
        :options="formatOptions"
        placeholder="Seleccioná un formato"
      />
    </div>

    <!-- Selección de columnas -->
    <div class="mb-4" v-if="exportsUiStore.availableColumns.length > 0">
      <label class="block mb-1 font-medium">Columnas a incluir</label>
      <a-checkbox-group
        v-model:value="exportsUiStore.selectedColumns"
        :options="columnCheckboxOptions"
      />
    </div>

    <template #footer>
      <div class="flex justify-end gap-2">
        <a-button @click="exportsUiStore.closeExportModal">Cancelar</a-button>
        <a-button
          type="primary"
          :loading="mutation.isPending.value"
          :disabled="!selectedFormat"
          @click="handleConfirm"
        >
          Exportar
        </a-button>
      </div>
    </template>
  </BaseModal>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import BaseModal from '@/components/atoms/overlays/BaseModal.vue'
import BaseSelect from '@/components/atoms/selects/BaseSelect.vue'
import { useExportsUiStore } from '../stores/exports-ui.store'
import { useInitiateExport } from '../composables/useInitiateExport'
import type { ExportFormat } from '../types/export.types'

const exportsUiStore = useExportsUiStore()
const mutation       = useInitiateExport()
const selectedFormat = ref<ExportFormat | null>(null)

const formatOptions = [
  { value: 'xlsx', label: 'Excel (.xlsx)' },
  { value: 'csv',  label: 'CSV (.csv)'    },
  { value: 'txt',  label: 'Texto (.txt)'  },
  { value: 'pdf',  label: 'PDF (.pdf)'    },
]

const columnCheckboxOptions = computed(() =>
  exportsUiStore.availableColumns.map(c => ({ label: c.label, value: c.key }))
)

async function handleConfirm() {
  if (!selectedFormat.value || !exportsUiStore.pendingExportType) return

  await mutation.mutateAsync({
    type:    exportsUiStore.pendingExportType as any,
    format:  selectedFormat.value,
    async:   false,
    filters: exportsUiStore.pendingFilters,
    columns: exportsUiStore.selectedColumns,
  })

  exportsUiStore.closeExportModal()
  selectedFormat.value = null
}
</script>
```

---

#### `front/src/modules/exports/components/ExportStatusBadge.vue`
**Propósito:** Badge de estado de exportación (reutilizable en la lista de exportaciones).
```vue
<template>
  <BaseBadge :color="color" :text="label" />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import BaseBadge from '@/components/atoms/display/BaseBadge.vue'
import type { ExportStatus } from '../types/export.types'

const props = defineProps<{ status: ExportStatus }>()

const map: Record<ExportStatus, { color: string; label: string }> = {
  pending:    { color: 'default', label: 'Pendiente'    },
  processing: { color: 'blue',    label: 'Procesando'   },
  completed:  { color: 'green',   label: 'Completado'   },
  failed:     { color: 'red',     label: 'Error'        },
}

const color = computed(() => map[props.status].color)
const label = computed(() => map[props.status].label)
</script>
```

---

### Archivos a modificar

#### `front/src/core/composables/useExportFormat.ts`
**Cambio:** Agregar `csv` a los formatos disponibles (actualmente tiene `pdf`, `xlsx`, `txt` pero no `csv`).
**Antes:** `EXPORT_FORMAT_OPTIONS` no incluye CSV.
**Después:** Agregar `{ value: 'csv', label: 'CSV (.csv)' }` al array. El tipo `ExportFormat` agregar `'csv'`.

Nota: Este archivo puede quedar como utilitario standalone o ser reemplazado por la lógica dentro de `ExportModal.vue`. Dado que el modal nuevo tiene su propio estado, `useExportFormat` puede deprecarse gradualmente o mantenerse para otros usos. Por ahora solo se le agrega `csv`.

---

#### `front/src/components/layouts/partials/AppSidebar.vue` (o el layout principal)
**Cambio:** Montar `ExportModal` globalmente una vez en el layout para que esté disponible en toda la app.
**Después:** Importar y agregar `<ExportModal />` en el template del layout principal. El componente se muestra/oculta vía el store.

---

#### `front/src/core/constants/permissions.ts`
**Cambio:** Agregar la constante del nuevo permiso.
**Antes:** Solo tiene permisos de users y roles. Nota: hay una discrepancia detectada (ver Riesgos).
**Después:** Agregar:
```typescript
EXPORTS_CREATE: 'exports.alta',
```

---

## Árbol de archivos completo

```
BACKEND (back/)
├── app/
│   ├── Console/Commands/
│   │   └── ExportsCleanupCommand.php                     [CREAR]
│   ├── Contracts/
│   │   ├── Exports/
│   │   │   ├── ExporterInterface.php                     [CREAR]
│   │   │   └── ExportResolverInterface.php               [CREAR]
│   │   └── Repositories/
│   │       ├── ExportRepositoryInterface.php             [CREAR]
│   │       └── UserRepositoryInterface.php               [MODIFICAR — agregar exportQuery()]
│   ├── Enums/
│   │   ├── ExportFormat.php                              [CREAR]
│   │   ├── ExportStatus.php                              [CREAR]
│   │   └── ExportType.php                               [CREAR]
│   ├── Events/
│   │   ├── ExportCompletedEvent.php                      [CREAR]
│   │   └── ExportFailedEvent.php                         [CREAR]
│   ├── Exports/
│   │   ├── Pdf/
│   │   │   └── BasePdfExporter.php                       [CREAR]
│   │   └── Users/
│   │       ├── UsersExport.php                           [CREAR]
│   │       ├── UsersExporter.php                         [CREAR]
│   │       ├── UsersTxtExporter.php                      [CREAR]
│   │       └── UsersPdfExporter.php                      [CREAR — extiende BasePdfExporter]
│   ├── Http/
│   │   ├── Controllers/V1/
│   │   │   └── ExportController.php                      [CREAR]
│   │   ├── Requests/Exports/
│   │   │   └── InitiateExportRequest.php                 [CREAR]
│   │   └── Resources/V1/
│   │       └── ExportResource.php                        [CREAR]
│   ├── Jobs/
│   │   └── ProcessExportJob.php                          [CREAR]
│   ├── Listeners/
│   │   ├── NotifyExportCompletedListener.php             [CREAR]
│   │   └── NotifyExportFailedListener.php                [CREAR]
│   ├── Models/
│   │   └── Export.php                                    [CREAR]
│   ├── Notifications/
│   │   ├── ExportReadyNotification.php                   [CREAR]
│   │   └── ExportFailedNotification.php                  [CREAR]
│   ├── Policies/
│   │   └── ExportPolicy.php                              [CREAR]
│   ├── Providers/
│   │   └── AppServiceProvider.php                        [MODIFICAR — bindings + events + policy]
│   ├── Repositories/
│   │   ├── ExportRepositoryEloquent.php                  [CREAR]
│   │   └── UserRepositoryEloquent.php                    [MODIFICAR — agregar exportQuery()]
│   └── Services/Exports/
│       ├── ExportResolverService.php                     [CREAR]
│       └── ExportService.php                             [CREAR]
├── database/
│   ├── migrations/
│   │   ├── 2026_05_18_000001_create_exports_table.php   [CREAR]
│   │   └── 2026_05_18_000002_create_notifications_table.php [CREAR — via artisan]
│   └── seeders/
│       ├── PermissionSeeder.php                          [MODIFICAR — agregar exports.alta]
│       └── RoleSeeder.php                                [MODIFICAR — asignar a admin]
└── routes/api/
    └── exports.php                                       [CREAR]

├── resources/views/exports/
│   └── generic.blade.php                                 [CREAR — vista genérica compartida por todos los módulos]

FRONTEND (front/)
└── src/
    ├── components/layouts/partials/
    │   └── AppSidebar.vue                                [MODIFICAR — montar ExportModal]
    ├── core/
    │   ├── composables/
    │   │   └── useExportFormat.ts                        [MODIFICAR — agregar csv]
    │   └── constants/
    │       └── permissions.ts                            [MODIFICAR — agregar EXPORTS_CREATE]
    └── modules/exports/
        ├── api/
        │   └── exports.api.ts                            [CREAR]
        ├── components/
        │   ├── ExportButton.vue                          [CREAR]
        │   ├── ExportModal.vue                           [CREAR]
        │   └── ExportStatusBadge.vue                     [CREAR]
        ├── composables/
        │   ├── useInitiateExport.ts                      [CREAR]
        │   └── useExports.ts                             [CREAR]
        ├── stores/
        │   └── exports-ui.store.ts                       [CREAR]
        └── types/
            └── export.types.ts                           [CREAR]
```

Total backend: **25 archivos nuevos + 5 modificados** (se suman `BasePdfExporter.php`, `UsersPdfExporter.php` y `resources/views/exports/generic.blade.php`).
Total frontend: **9 archivos nuevos + 3 modificados**.

---

## Flujo completo

### Exportación síncrona

```
1. Usuario en UsersPage hace click en "Exportar"
   → ExportButton.vue captura el evento
   → Llama a exportsUiStore.openExportModal({ exportType: 'users', filters: {...}, availableColumns: [...] })

2. ExportModal.vue se muestra (isModalOpen = true)
   → Usuario selecciona formato: 'xlsx'
   → Usuario (des)selecciona columnas
   → Click en "Exportar"

3. ExportModal llama useInitiateExport().mutateAsync({
     type: 'users', format: 'xlsx', async: false,
     filters: {...}, columns: [...]
   })

4. initiateExportApi() hace POST /v1/exports
   → Sanctum verifica token
   → ExportController@store
   → InitiateExportRequest valida
   → $this->authorize('create', [Export::class, 'users'])
   → ExportPolicy::create() verifica 'exports.alta'

5. ExportService::initiate() [async=false]
   → Crea registro en 'exports' (status=pending)
   → Llama process() directamente

6. ExportService::process()
   → updateStatus(PROCESSING)
   → ExportResolverService::resolve('users', 'xlsx') → UsersExporter
   → UsersExporter::export(filters, columns, filePath)
     → UserRepositoryInterface::exportQuery(filters)->get()
     → Instancia UsersExport(collection, columns)
     → Excel::store(exporter, filePath, 'local', XLSX)
   → updateStatus(COMPLETED)
   → Retorna Export [status=completed]

7. ExportController retorna 200 con ExportResource
   → { guid, status: 'completed', is_downloadable: true, ... }

8. useInitiateExport.onSuccess()
   → exportItem.status === 'completed' && is_downloadable
   → downloadExportApi(guid, fileName)
     → GET /v1/exports/{guid}/download
     → ExportController@download
     → ExportPolicy::download() verifica ownership
     → response()->download(storage_path, fileName, mimeType)
   → Navegador descarga el archivo
   → Notificación success: "Exportación completada. Descargando..."

9. ExportModal se cierra.
```

### Exportación asíncrona

```
1. Usuario hace click en "Exportar" (con async=true o dataset grande)
   → Igual que síncrono hasta POST /v1/exports, pero { async: true }

2. ExportService::initiate() [async=true]
   → Crea registro en 'exports' (status=pending)
   → ProcessExportJob::dispatch(export->id)
   → Retorna Export [status=pending]

3. ExportController retorna 202
   → useInitiateExport.onSuccess()
   → exportItem.status === 'pending'
   → Notificación info: "Exportación en proceso. Te notificaremos cuando esté lista."
   → ExportModal se cierra

4. [En background] Worker procesa la cola:
   ProcessExportJob::handle()
   → Export::findOrFail(id)
   → ExportService::process(export)
     → updateStatus(PROCESSING)
     → Resolver → Exporter → genera archivo
     → updateStatus(COMPLETED)
   → event(new ExportCompletedEvent(export))

5. NotifyExportCompletedListener::handle()
   → export->user->notify(new ExportReadyNotification(export))
   → Inserta en tabla 'notifications' (canal database)

6. [Frontend] useExports() tiene refetchInterval activo (10s)
   porque había exportaciones en status pending/processing.
   → Al completarse, la próxima query retorna status=completed
   → El componente de lista muestra el badge "Completado" y el botón de descarga

7. Usuario hace click en "Descargar"
   → downloadExportApi(guid, fileName)
   → Mismo flujo de descarga que el síncrono
```

---

## Orden de implementación

El orden está diseñado para que cada paso sea testeable de forma independiente antes de avanzar.

1. **Instalar packages de backend.** En `back/`:
   ```bash
   composer require maatwebsite/excel
   composer require barryvdh/laravel-dompdf
   ```
   Publicar config de Excel: `php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider"`.

2. **Crear los Enums**: `ExportFormat`, `ExportStatus`, `ExportType`.

3. **Crear la migration `create_exports_table`** y ejecutarla.
   ```bash
   php artisan migrate
   ```

4. **Crear la migration de notificaciones** y ejecutarla.
   ```bash
   php artisan notifications:table
   php artisan migrate
   ```

5. **Crear el modelo `Export`** con el trait `HasGuid`.

6. **Crear las interfaces**: `ExporterInterface`, `ExportResolverInterface`, `ExportRepositoryInterface`.

7. **Modificar `UserRepositoryInterface`** agregando `exportQuery()`.

8. **Modificar `UserRepositoryEloquent`** implementando `exportQuery()`.

9. **Crear `ExportRepositoryEloquent`**.

10. **Crear la clase `UsersExport`** (el objeto de Maatwebsite/Excel).

11. **Crear `BasePdfExporter`** en `back/app/Exports/Pdf/`. Es la base abstracta para todos los PDF exporters del sistema.

11a. **Crear la vista Blade genérica** `back/resources/views/exports/generic.blade.php`. Verificar que DomPDF la renderiza correctamente ejecutando desde tinker:
   ```php
   \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.generic', [
       'title'         => 'Test',
       'activeColumns' => ['nombre' => 'Nombre', 'email' => 'Email'],
       'rows'          => [['Juan', 'juan@test.com']],
       'total'         => 1,
   ])->save(storage_path('app/private/test.pdf'));
   ```

11b. **Crear `UsersExporter`**, **`UsersTxtExporter`** y **`UsersPdfExporter`** (este último extiende `BasePdfExporter`).

12. **Crear `ExportResolverService`** — verificar que el resolve funciona con un test unitario básico.

13. **Crear `ExportService`** — con modo síncrono únicamente (sin Job por ahora). Testear que genera el archivo.

14. **Crear `InitiateExportRequest`** y **`ExportResource`**.

15. **Crear `ExportPolicy`**.

16. **Crear `ExportController`** (sin el modo asíncrono por ahora).

17. **Crear `back/routes/api/exports.php`** y verificar que las rutas están activas.

18. **Modificar `PermissionSeeder`** y **`RoleSeeder`**. Correr: `php artisan db:seed`.

19. **Registrar bindings y policy** en `AppServiceProvider`.

20. **Test manual del flujo síncrono**: POST /v1/exports con Postman/curl, verificar que el archivo se genera en storage y que la descarga funciona.

21. **Crear `ProcessExportJob`**.

22. **Crear `ExportCompletedEvent`**, **`ExportFailedEvent`**, **`NotifyExportCompletedListener`**, **`NotifyExportFailedListener`**.

23. **Crear `ExportReadyNotification`** y **`ExportFailedNotification`**.

24. **Registrar eventos en `AppServiceProvider`**.

25. **Habilitar modo asíncrono en `ExportService` y `ExportController`**.

26. **Crear `ExportsCleanupCommand`** y registrarlo en `Kernel` (o `routes/console.php`).

27. **Escribir Feature Tests y Unit Tests**.

28. **(Frontend) Modificar `useExportFormat.ts`** para agregar CSV.

29. **(Frontend) Crear `export.types.ts`**.

30. **(Frontend) Crear `exports.api.ts`** con el manejo especial del blob para la descarga.

31. **(Frontend) Crear `exports-ui.store.ts`**.

32. **(Frontend) Crear composables `useInitiateExport`** y **`useExports`**.

33. **(Frontend) Crear componentes `ExportButton.vue`**, **`ExportModal.vue`**, **`ExportStatusBadge.vue`**.

34. **(Frontend) Montar `ExportModal` en el layout principal** (AppSidebar o DashboardLayout).

35. **(Frontend) Integrar `ExportButton` en la página de Usuarios** (`UsersPage`) como primera prueba de integración.

36. **(Frontend) Agregar constante `EXPORTS_CREATE` en `permissions.ts`**.

37. **Test de integración end-to-end** del flujo síncrono desde la UI.

---

## Riesgos y consideraciones

**RIESGO 1 — Discrepancia en naming de permisos (CRÍTICO):**
El archivo `front/src/core/constants/permissions.ts` usa nombres en inglés (`users.view`, `users.create`, etc.) mientras que el backend tiene `users.lectura`, `users.alta`, etc. El `PermissionGuard` y `usePermission().can()` comparan strings exactos desde el auth store (que vienen del backend). Esto significa que actualmente `PERMISSIONS.USERS_VIEW` nunca va a matchear con `users.lectura` del backend. Esta discrepancia preexiste al presente plan y debe investigarse antes o durante la implementación. En este plan se usa `'exports.alta'` directamente (el nombre del backend) para no agregar más deuda. Se recomienda auditar y corregir `permissions.ts` en una tarea paralela o como prerequisito.

**RIESGO 2 — Disco local y archivos servidos:**
El disco `local` tiene `root = storage_path('app/private')`. `storage_path('app/private/exports/...')` es el path correcto para `response()->download()`. Si en el futuro el deploy cambia a un servidor con filesystem compartido o S3, habrá que migrar la lógica del controller. El disco está abstraído detrás del repositorio, lo que facilita esa migración futura.

**RIESGO 3 — Memoria en exportaciones síncronas grandes:**
`exportQuery()->get()` carga todos los registros en memoria. Para datasets muy grandes (> 50k filas), esto puede causar OOM. Solución: usar `->chunk()` o `->cursor()` en `UsersExporter::export()` junto con `FromQuery` de Maatwebsite/Excel en lugar de `FromCollection`. En esta iteración se acepta la limitación y se documenta. La flag `async` permite al usuario procesar grandes datasets en background, lo que mitiga el problema en la mayoría de los casos.

**RIESGO 4 — Interceptor HTTP y descarga de binarios:**
El interceptor de Axios detecta `{ success: true/false }` en la response. Para `responseType: 'blob'`, el `response.data` es un `Blob` y la condición `payload['success'] === true` es `false` (porque `Blob` no tiene esa propiedad), por lo que pasa al segundo `if` (`payload['success'] === false`) que tampoco aplica, y retorna la response sin modificar. Esto funciona correctamente. Si en el futuro se modificara el interceptor, revisar que no rompa las descargas de blob.

**RIESGO 5 — Cola y worker:**
El sistema asíncrono requiere un worker corriendo (`php artisan queue:work`). En Laragon (desarrollo) hay que levantarlo manualmente. En producción debe gestionarse con Supervisor. Esto es un riesgo operativo, no de código. Documentarlo en el deploy checklist.

**RIESGO 6 — `maatwebsite/excel` y compatibilidad con Laravel 12:**
Verificar que la versión requerida de `maatwebsite/excel` (v3.x) es compatible con Laravel 12 antes de hacer `composer require`. En el momento de escritura de este plan, Laravel 12 puede requerir `^3.1` o superior. Corroborar en el composer.json del package.

**RIESGO 7 — Limitaciones de CSS en DomPDF:**
DomPDF no soporta CSS moderno (flexbox, grid, `gap`, `border-radius` en celdas de tabla). La vista `exports/generic.blade.php` usa únicamente `display: table`, márgenes, bordes simples y colores de fondo. Si en el futuro se crea una vista personalizada para un módulo (`exports/{modulo}.blade.php`), respetar las mismas restricciones. Un CSS inválido puede generar un PDF con layout roto sin lanzar excepción — verificar visualmente con el comando tinker del paso 11a antes de dar por terminado el exporter.

---

## Supuestos hechos

- La tabla `jobs` ya existe (verificado en la migration `0001_01_01_000002_create_jobs_table.php`).
- El proyecto usa la cola `database` como driver por defecto (`QUEUE_CONNECTION=database` en `queue.php`).
- El modelo `User` tiene el trait `Notifiable` (verificado en `User.php`), lo que habilita el canal `database` de notificaciones.
- No existe un `EventServiceProvider` separado; los eventos se registran en `AppServiceProvider::boot()` (convención de Laravel 12).
- El `AuthServiceProvider` tampoco existe separado; las policies se registran con `Gate::policy()` en `AppServiceProvider::boot()`.
- `barryvdh/laravel-dompdf` se instala e implementa en esta iteración con `UsersPdfExporter` y la vista `exports/users.blade.php`.
- El directorio `back/app/Exports/` no existe aún — se crea como parte de este plan.
- El directorio `back/app/Enums/` no existe aún — se crea como parte de este plan.
- El directorio `back/app/Events/` y `back/app/Listeners/` no existen aún — se crean como parte de este plan.

---

## Pendientes / fuera de alcance

- **Página de historial de exportaciones**: componente de listado completo (`ExportsHistoryPage`) con paginación y botones de re-descarga. Está fuera de alcance pero los composables `useExports` y `ExportStatusBadge` ya la soportan.
- **Exportaciones de otros módulos** (roles, permisos): se agrega un nuevo case en `ExportResolverService::resolve()` y el `ExportType` enum. El resto del sistema no cambia.
- **Detección automática síncrono/asíncrono por COUNT**: mejora futura en `ExportService::initiate()`.
- **Scheduling del comando `exports:cleanup`**: requiere configuración de servidor (crontab / Laravel Scheduler). Se registra el comando pero no se schedula.
- **Notificaciones en tiempo real** (WebSocket/SSE): fuera de alcance. El polling de 10s es suficiente para la iteración actual.
- **Exportación con template personalizado** (branding en PDF, colores de marca): fuera de alcance.
- **Tests de la UI (Vitest/Playwright)**: el plan cubre tests de backend. Los tests de frontend se planifican en iteración separada.
```

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <style>
        body    { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1      { font-size: 16px; margin-bottom: 2px; }
        h2      { font-size: 12px; margin: 0 0 6px 0; }
        .meta   { color: #6b7280; font-size: 9px; margin-bottom: 12px; }
        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .info-table td { padding: 3px 6px; font-size: 9px; }
        .info-table td.label { color: #6b7280; width: 140px; }
        .group  { margin-bottom: 18px; page-break-inside: avoid; }
        .group-header { background-color: #eef2ff; padding: 6px 8px; border-radius: 4px; margin-bottom: 6px; }
        table   { width: 100%; border-collapse: collapse; }
        th      { background-color: #374151; color: #ffffff; padding: 6px 8px; text-align: left; font-size: 9px; }
        td      { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9px; }
        tr.task-important td { background-color: #fef3c7; border-left: 3px solid #d97706; }
    </style>
</head>
<body>
    <h1>Programa: {{ $program->protocol->name }}</h1>
    <p class="meta">Generado el {{ now()->format('d/m/Y H:i') }}</p>

    <table class="info-table">
        <tr>
            <td class="label">Cliente</td><td>{{ $program->client->name }}</td>
            <td class="label">Establecimiento</td><td>{{ $program->establishment->name }}</td>
        </tr>
        <tr>
            <td class="label">Técnica</td><td>{{ $program->technique->name }}</td>
            <td class="label">Protocolo</td><td>{{ $program->protocol->name }}</td>
        </tr>
        <tr>
            <td class="label">Estado</td>
            <td>{{ $program->cancelled_at ? 'Cancelado' : 'Activo' }}</td>
            <td class="label">Comentarios</td>
            <td>{{ $program->comments ?? '-' }}</td>
        </tr>
    </table>

    @forelse($groups as $group)
        @php
            $target = $group['target'];
            $tasks  = $group['tasks'];
        @endphp
        <div class="group">
            <div class="group-header">
                <h2>
                    Objetivo del {{ $target->target_date->format('d/m/Y') }}
                    @if($target->animals->isNotEmpty())
                        &mdash; Animales: {{ $target->animals->pluck('rp')->join(', ') }}
                    @endif
                </h2>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Descripción</th>
                        <th>Notifica</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($tasks as $task)
                        <tr @if($task['important']) class="task-important" @endif>
                            <td>{{ \Illuminate\Support\Carbon::parse($task['occurs_on'])->format('d/m/Y') }}</td>
                            <td>{{ $task['description'] }}</td>
                            <td>{{ $task['notifies'] ? 'Sí' : 'No' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" style="text-align:center;color:#6b7280;">Sin tareas</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @empty
        <p>El programa no tiene objetivos definidos.</p>
    @endforelse
</body>
</html>

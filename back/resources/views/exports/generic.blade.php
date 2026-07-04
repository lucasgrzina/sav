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
        &nbsp;&middot;&nbsp;
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

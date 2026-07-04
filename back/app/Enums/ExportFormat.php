<?php

namespace App\Enums;

enum ExportFormat: string
{
    case XLSX = 'xlsx';
    case CSV  = 'csv';
    case TXT  = 'txt';
    case PDF  = 'pdf';

    public function mimeType(): string
    {
        return match ($this) {
            self::XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::CSV  => 'text/csv',
            self::TXT  => 'text/plain',
            self::PDF  => 'application/pdf',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::XLSX => 'Excel (.xlsx)',
            self::CSV  => 'CSV (.csv)',
            self::TXT  => 'Texto (.txt)',
            self::PDF  => 'PDF (.pdf)',
        };
    }
}

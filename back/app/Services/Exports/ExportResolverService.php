<?php

namespace App\Services\Exports;

use App\Contracts\Exports\ExportResolverInterface;
use App\Contracts\Exports\ExporterInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Enums\ExportFormat;
use App\Enums\ExportType;
use App\Exports\Roles\RolesExporter;
use App\Exports\Roles\RolesPdfExporter;
use App\Exports\Roles\RolesTxtExporter;
use App\Exports\Users\UsersExporter;
use App\Exports\Users\UsersPdfExporter;
use App\Exports\Users\UsersTxtExporter;

class ExportResolverService implements ExportResolverInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly RoleRepositoryInterface $roleRepository,
    ) {}

    public function resolve(string $exportType, string $format): ExporterInterface
    {
        $typeEnum   = ExportType::from($exportType);
        $formatEnum = ExportFormat::from($format);

        return match (true) {
            $typeEnum === ExportType::USERS && in_array($formatEnum, [ExportFormat::XLSX, ExportFormat::CSV])
                => new UsersExporter($this->userRepository, $formatEnum),

            $typeEnum === ExportType::USERS && $formatEnum === ExportFormat::TXT
                => new UsersTxtExporter($this->userRepository),

            $typeEnum === ExportType::USERS && $formatEnum === ExportFormat::PDF
                => new UsersPdfExporter($this->userRepository),

            $typeEnum === ExportType::ROLES && in_array($formatEnum, [ExportFormat::XLSX, ExportFormat::CSV])
                => new RolesExporter($this->roleRepository, $formatEnum),

            $typeEnum === ExportType::ROLES && $formatEnum === ExportFormat::TXT
                => new RolesTxtExporter($this->roleRepository),

            $typeEnum === ExportType::ROLES && $formatEnum === ExportFormat::PDF
                => new RolesPdfExporter($this->roleRepository),

            default => throw new \InvalidArgumentException(
                "Combinación de tipo '{$exportType}' y formato '{$format}' no soportada."
            ),
        };
    }

    public function availableFormats(string $exportType): array
    {
        return match (ExportType::from($exportType)) {
            ExportType::USERS,
            ExportType::ROLES => [
                ExportFormat::XLSX->value,
                ExportFormat::CSV->value,
                ExportFormat::TXT->value,
                ExportFormat::PDF->value,
            ],
        };
    }
}

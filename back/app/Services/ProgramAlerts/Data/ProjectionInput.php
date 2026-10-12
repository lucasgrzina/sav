<?php

namespace App\Services\ProgramAlerts\Data;

use App\Models\Program;
use App\Models\Protocol;
use App\Models\UserProfile;
use App\Models\Vet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A "draft" program: enough to project alerts without a persisted Program, so the same
 * projector serves the detail, the preview, the warnings and the real generation.
 */
final readonly class ProjectionInput
{
    /**
     * @param list<TargetInput> $targets
     * @param Collection<int, UserProfile> $managers with `role` (and `user`, `contacts` when needed)
     */
    public function __construct(
        public Vet $vet,
        public ?string $programGuid,
        public Protocol $protocol,
        public array $targets,
        public Collection $managers,
        public CarbonImmutable $now,
        public string $locale,
    ) {}

    public static function fromProgram(Program $program, CarbonImmutable $now): self
    {
        $program->loadMissing('targets', 'protocol.tasks.alerts', 'managers.role', 'managers.user', 'vet.country');

        return new self(
            vet: $program->vet,
            programGuid: $program->guid,
            protocol: $program->protocol,
            targets: $program->targets
                ->map(fn ($target) => new TargetInput($target->guid, $target->guid, $target->target_date->toDateString()))
                ->values()
                ->all(),
            managers: $program->managers,
            now: $now,
            locale: self::localeFor($program->vet),
        );
    }

    public static function localeFor(Vet $vet): string
    {
        $map = (array) config('notifications.locale_by_country_iso', []);

        return $map[$vet->country?->iso_code] ?? $map['default'] ?? 'es';
    }
}

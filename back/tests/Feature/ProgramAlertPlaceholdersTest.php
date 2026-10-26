<?php

namespace Tests\Feature;

use App\Events\ProgramTargetsChangedEvent;
use App\Models\Animal;
use App\Models\Client;
use App\Models\Country;
use App\Models\DocumentType;
use App\Models\Establishment;
use App\Models\Program;
use App\Models\ProgramTarget;
use App\Models\Protocol;
use App\Models\ProtocolTask;
use App\Models\ProtocolTaskAlert;
use App\Models\Role;
use App\Models\Technique;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\Vet;
use App\Notifications\Enums\AlertType;
use App\Notifications\Models\Alert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real generation path: protocol message with placeholders -> persisted alert payload. */
class ProgramAlertPlaceholdersTest extends TestCase
{
    use RefreshDatabase;

    private const MESSAGE = 'Grupo [grupo] de [establecimiento]: [fecha del día 0] y [fecha del día +1]';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_generated_alert_payload_has_establishment_group_name_and_dates_resolved(): void
    {
        $program = $this->programWithAlertMessage(self::MESSAGE);
        ProgramTarget::create(['program_id' => $program->id, 'target_date' => '2030-09-24', 'name' => 'Lote Norte']);

        $this->assertSame(
            'Grupo Lote Norte de La Estancia: martes 24/09/2030 y miércoles 25/09/2030',
            $this->generatedMessage($program),
        );
    }

    public function test_unnamed_group_falls_back_to_date_and_animal_count(): void
    {
        $program = $this->programWithAlertMessage(self::MESSAGE);
        $target = ProgramTarget::create(['program_id' => $program->id, 'target_date' => '2030-09-24']);
        $target->animals()->attach($this->animals($program, 3));

        $this->assertSame(
            'Grupo del 24/09/2030 (3 animales) de La Estancia: martes 24/09/2030 y miércoles 25/09/2030',
            $this->generatedMessage($program),
        );
    }

    public function test_unnamed_group_with_one_animal_uses_singular(): void
    {
        $program = $this->programWithAlertMessage(self::MESSAGE);
        $target = ProgramTarget::create(['program_id' => $program->id, 'target_date' => '2030-09-24']);
        $target->animals()->attach($this->animals($program, 1));

        $this->assertStringStartsWith('Grupo del 24/09/2030 (1 animal) de', $this->generatedMessage($program));
    }

    public function test_blank_name_is_treated_as_unnamed(): void
    {
        $program = $this->programWithAlertMessage('[grupo]');
        ProgramTarget::create(['program_id' => $program->id, 'target_date' => '2030-09-24', 'name' => '   ']);

        $this->assertSame('del 24/09/2030 (0 animales)', $this->generatedMessage($program));
    }

    private function generatedMessage(Program $program): string
    {
        event(new ProgramTargetsChangedEvent(Program::findOrFail($program->id)));

        return Alert::where('type', AlertType::ProgramTaskDue)->where('subject_id', $program->id)->firstOrFail()->payload['message'];
    }

    /** @return list<int> */
    private function animals(Program $program, int $count): array
    {
        return collect(range(1, $count))
            ->map(fn (int $i) => Animal::create(['guid' => Str::uuid()->toString(), 'client_id' => $program->client_id, 'rp' => "RP{$i}", 'type' => 'livestock'])->id)
            ->all();
    }

    private function programWithAlertMessage(string $message): Program
    {
        Carbon::setTestNow('2030-06-01 10:00:00');

        $role = Role::create(['guid' => Str::uuid()->toString(), 'name' => 'client-manager', 'guard_name' => 'web', 'type' => Role::TYPE_TENANT]);
        $country = Country::create(['guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54']);
        $docType = DocumentType::create(['guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*']);
        $vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet', 'slug' => 'vet-' . Str::random(8),
            'country_id' => $country->id, 'document_type_id' => $docType->id, 'tax_id' => '20-11111111-9', 'validated_at' => now(),
        ]);
        $client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente', 'country_id' => $country->id,
            'document_type_id' => $docType->id, 'tax_id' => '20-22222222-1',
        ]);
        $client->vets()->attach($vet);
        $establishment = Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $client->id, 'name' => 'La Estancia']);
        $technique = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IATF', 'type' => 'technique']);
        $protocol = Protocol::create([
            'guid' => Str::uuid()->toString(), 'technique_id' => $technique->id, 'vet_id' => $vet->id,
            'created_by_type' => 'vet', 'created_by_id' => 1, 'name' => 'Papel de trabajo 3',
        ]);
        $task = ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $protocol->id, 'description' => 'Primer turno',
            'days_offset' => 0, 'time_of_day' => 'before', 'time' => '08:00', 'important' => false, 'sort_order' => 1,
        ]);
        ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id, 'offset_days' => 1,
            'time_of_day' => 'before', 'time' => '17:00', 'roles' => ['client-manager'],
            'message' => $message, 'require_confirmation' => false, 'sort_order' => 1,
        ]);
        $manager = UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => User::factory()->create()->id,
            'authenticatable_type' => 'client', 'authenticatable_id' => $client->id, 'role_id' => $role->id,
        ]);
        $program = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $vet->id, 'client_id' => $client->id,
            'establishment_id' => $establishment->id, 'technique_id' => $technique->id, 'protocol_id' => $protocol->id,
        ]);
        $program->managers()->sync([$manager->id]);

        return $program;
    }
}

<?php

namespace Tests\Unit\Notifications;

use App\Enums\ContactType;
use App\Events\ProgramCancelledEvent;
use App\Models\Client;
use App\Models\Contact;
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
use App\Notifications\Builders\ProgramTaskDueMessageBuilder;
use App\Notifications\Contracts\AlertMessageBuilder;
use App\Notifications\Data\DeliveryResult;
use App\Notifications\Data\MessageContent;
use App\Notifications\Data\Recipient;
use App\Notifications\Data\TextContent;
use App\Notifications\Enums\AlertType;
use App\Notifications\Enums\Channel;
use App\Notifications\Enums\DeliveryStatus;
use App\Notifications\Enums\FailureCode;
use App\Notifications\Gateways\Fake\FakeGateway;
use App\Notifications\Jobs\DeliverAlertJob;
use App\Notifications\Models\Alert;
use App\Notifications\Models\AlertRecipient;
use App\Notifications\Pipeline\DeliveryPipeline;
use App\Notifications\Registries\GatewayRegistry;
use App\Notifications\Registries\MessageBuilderRegistry;
use App\Notifications\Revalidators\ProgramTaskDueRevalidator;
use App\Notifications\Services\ChannelFallbackService;
use App\Services\ProgramAlerts\ProgramAlertReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Send-time revalidation (H3): a task_due alert that was valid at dispatch must be suppressed
 * (no email fallback) when, by the time of a queue retry, it no longer applies.
 */
class ProgramTaskDueRevalidatorTest extends TestCase
{
    use RefreshDatabase;

    private Vet $vet;
    private Program $program;
    private Protocol $protocol;
    private ProtocolTaskAlert $protocolAlert;
    private UserProfile $owner;
    private UserProfile $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2030-06-01 10:00:00');

        $ownerRole   = Role::create(['guid' => Str::uuid()->toString(), 'name' => 'client-owner', 'guard_name' => 'web', 'type' => Role::TYPE_TENANT]);
        $managerRole = Role::create(['guid' => Str::uuid()->toString(), 'name' => 'client-manager', 'guard_name' => 'web', 'type' => Role::TYPE_TENANT]);

        $country = Country::create(['guid' => Str::uuid()->toString(), 'name' => 'Argentina', 'iso_code' => 'AR', 'phone_prefix' => '+54']);
        $docType = DocumentType::create(['guid' => Str::uuid()->toString(), 'country_id' => $country->id, 'name' => 'CUIT', 'validation_regex' => '.*']);
        $this->vet = Vet::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Vet', 'slug' => 'vet-' . Str::random(8),
            'country_id' => $country->id, 'document_type_id' => $docType->id, 'tax_id' => '20-11111111-9', 'validated_at' => now(),
        ]);
        $client = Client::create([
            'guid' => Str::uuid()->toString(), 'name' => 'Cliente', 'country_id' => $country->id,
            'document_type_id' => $docType->id, 'tax_id' => '20-22222222-1',
        ]);
        $client->vets()->attach($this->vet);
        $establishment = Establishment::create(['guid' => Str::uuid()->toString(), 'client_id' => $client->id, 'name' => 'Estancia']);
        $technique     = Technique::create(['guid' => Str::uuid()->toString(), 'name' => 'IA', 'type' => 'technique']);
        $this->protocol = Protocol::create([
            'guid' => Str::uuid()->toString(), 'technique_id' => $technique->id, 'vet_id' => $this->vet->id,
            'created_by_type' => 'vet', 'created_by_id' => 1, 'name' => 'Protocolo',
        ]);
        $task = ProtocolTask::create([
            'guid' => Str::uuid()->toString(), 'protocol_id' => $this->protocol->id, 'description' => 'Tarea',
            'days_offset' => 0, 'time_of_day' => 'after', 'time' => '08:00', 'important' => false, 'sort_order' => 1,
        ]);
        $this->protocolAlert = ProtocolTaskAlert::create([
            'guid' => Str::uuid()->toString(), 'protocol_task_id' => $task->id, 'offset_days' => 0,
            'time_of_day' => 'after', 'time' => '08:30', 'roles' => ['client-owner', 'client-manager'],
            'message' => 'Recordatorio', 'require_confirmation' => false, 'sort_order' => 1,
        ]);

        $this->owner   = $this->profile($client, $ownerRole);
        $this->manager = $this->profile($client, $managerRole);

        $this->program = Program::create([
            'guid' => Str::uuid()->toString(), 'vet_id' => $this->vet->id, 'client_id' => $client->id,
            'establishment_id' => $establishment->id, 'technique_id' => $technique->id, 'protocol_id' => $this->protocol->id,
        ]);
        $this->program->managers()->sync([$this->owner->id, $this->manager->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- Revalidator rules -------------------------------------------------------------------

    public function test_a_v2_alert_that_still_matches_live_data_is_valid(): void
    {
        [$alert, $recipient] = $this->dispatchedAlert();

        $this->assertNull($this->revalidator()->check($alert, $recipient));
    }

    public function test_a_cancelled_alert_is_not_valid(): void
    {
        [$alert, $recipient] = $this->dispatchedAlert();
        $alert->update(['status' => 'cancelled']);

        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    public function test_a_cancelled_program_is_not_valid(): void
    {
        [$alert, $recipient] = $this->dispatchedAlert();
        $this->program->update(['cancelled_at' => now()]);

        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    public function test_a_profile_that_is_no_longer_responsible_is_not_valid(): void
    {
        [$alert, $recipient] = $this->dispatchedAlert();
        $this->program->managers()->detach($this->owner->id);

        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    public function test_a_role_removed_from_the_protocol_alert_is_not_valid(): void
    {
        [$alert, $recipient] = $this->dispatchedAlert(); // recipient is the owner
        $this->protocolAlert->update(['roles' => ['client-manager']]);

        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    public function test_a_group_that_changed_date_is_not_valid(): void
    {
        [$alert, $recipient] = $this->dispatchedAlert();
        ProgramTarget::where('program_id', $this->program->id)->update(['target_date' => '2030-07-01']);

        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    public function test_a_removed_group_is_not_valid(): void
    {
        [$alert, $recipient] = $this->dispatchedAlert();
        ProgramTarget::where('program_id', $this->program->id)->delete();

        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    public function test_a_deleted_protocol_is_not_valid_instead_of_throwing(): void
    {
        [$alert, $recipient] = $this->dispatchedAlert();
        DB::statement('PRAGMA defer_foreign_keys = ON'); // checked at commit, which never happens inside the test transaction
        DB::table('protocols')->where('id', $this->protocol->id)->delete();

        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    public function test_a_manager_without_role_is_not_valid_instead_of_throwing(): void
    {
        [$alert, $recipient] = $this->dispatchedAlert();
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('roles')->where('id', $this->owner->role_id)->delete();

        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    public function test_a_legacy_alert_is_valid_while_program_active_responsible_and_protocol_alert_remain(): void
    {
        [$alert, $recipient] = $this->legacyDispatchedAlert();

        $this->assertNull($this->revalidator()->check($alert, $recipient));

        $this->protocolAlert->delete();
        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    public function test_a_legacy_alert_is_not_valid_once_the_program_is_cancelled_or_the_profile_removed(): void
    {
        [$alert, $recipient] = $this->legacyDispatchedAlert();

        $this->program->managers()->detach($this->owner->id);
        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));

        $this->program->managers()->attach($this->owner->id);
        $this->program->update(['cancelled_at' => now()]);
        $this->assertSame(FailureCode::NoLongerApplicable, $this->revalidator()->check($alert->fresh(), $recipient));
    }

    // ---- DeliverAlertJob ---------------------------------------------------------------------

    public function test_job_retry_after_program_cancelled_suppresses_without_fallback(): void
    {
        [, $recipient] = $this->dispatchedAlert();
        $this->program->update(['cancelled_at' => now()]);
        Queue::fake();

        $gateway = $this->runJob($recipient);

        $this->assertSuppressed($recipient);
        $this->assertCount(0, $gateway->sentMessages());
        Queue::assertNothingPushed();
    }

    public function test_job_retry_after_responsible_removed_suppresses_without_fallback(): void
    {
        [, $recipient] = $this->dispatchedAlert();
        $this->program->managers()->detach($this->owner->id);
        Queue::fake();

        $gateway = $this->runJob($recipient);

        $this->assertSuppressed($recipient);
        $this->assertCount(0, $gateway->sentMessages());
        Queue::assertNothingPushed();
    }

    public function test_job_retry_after_group_date_changed_suppresses_the_old_dispatched_alert(): void
    {
        [, $recipient] = $this->dispatchedAlert();
        ProgramTarget::where('program_id', $this->program->id)->update(['target_date' => '2030-07-01']);
        Queue::fake();

        $gateway = $this->runJob($recipient);

        $this->assertSuppressed($recipient);
        $this->assertCount(0, $gateway->sentMessages());
        Queue::assertNothingPushed();
    }

    public function test_job_sends_a_valid_legacy_alert(): void
    {
        [, $recipient] = $this->legacyDispatchedAlert();

        $gateway = $this->runJob($recipient);

        $this->assertSame(DeliveryStatus::Sent, $recipient->fresh()->status);
        $this->assertCount(1, $gateway->sentMessages());
    }

    public function test_with_the_v2_flag_off_a_reconciler_alert_is_delivered_with_the_v1_variables(): void
    {
        config(['notifications.templates.program_task_due_v2' => false]);
        [$alert, $recipient] = $this->dispatchedAlert();
        $this->assertSame(2, $alert->payload['contextVersion']); // written regardless of the flag

        $gateway = $this->runJob($recipient, new ProgramTaskDueMessageBuilder());

        $sent = $gateway->sentMessages();
        $this->assertCount(1, $sent);
        $this->assertSame([1, 2, 3], array_keys($sent[0]->content->variables));
        $this->assertSame(1, $sent[0]->content->version);
        $this->assertSame(1, $recipient->fresh()->template_version);
        $this->assertNotNull($recipient->fresh()->rendered_body);
    }

    // ---- HandleProgramCancelledListener ------------------------------------------------------

    public function test_cancelling_a_program_soft_cancels_pending_alerts_and_keeps_the_rows(): void
    {
        $this->reconcile();
        $alert = $this->taskDueAlerts()->firstOrFail();
        $recipientCount = AlertRecipient::where('alert_id', $alert->id)->count();
        $this->program->update(['cancelled_at' => now()]);

        event(new ProgramCancelledEvent($this->program->fresh()->load('managers.user')));

        $alert->refresh();
        $this->assertSame('cancelled', $alert->status);
        $this->assertSame('program_cancelled', $alert->cancel_reason->value);
        $this->assertSame($recipientCount, AlertRecipient::where('alert_id', $alert->id)->count());
        $this->assertSame(1, Alert::where('type', AlertType::ProgramCancelled)->where('subject_id', $this->program->id)->count());
    }

    // ---- Helpers -----------------------------------------------------------------------------

    private function revalidator(): ProgramTaskDueRevalidator
    {
        return app(ProgramTaskDueRevalidator::class);
    }

    private function reconcile(): void
    {
        ProgramTarget::firstOrCreate(['program_id' => $this->program->id], ['target_date' => '2030-06-10']);
        app(ProgramAlertReconciler::class)->reconcile(Program::findOrFail($this->program->id), 'program_created');
    }

    private function taskDueAlerts()
    {
        return Alert::where('type', AlertType::ProgramTaskDue)->where('subject_type', 'program')->where('subject_id', $this->program->id);
    }

    /** A v2 alert created by the reconciler, already claimed by the dispatcher. @return array{Alert, AlertRecipient} */
    private function dispatchedAlert(): array
    {
        $this->reconcile();
        $alert = $this->taskDueAlerts()->firstOrFail();
        $alert->update(['status' => 'dispatched']);

        return [$alert->fresh(), $this->whatsappRecipient($alert, $this->owner)];
    }

    /** @return array{Alert, AlertRecipient} */
    private function legacyDispatchedAlert(): array
    {
        $alert = new Alert([
            'type' => AlertType::ProgramTaskDue,
            'payload' => ['protocolTaskAlertGuid' => $this->protocolAlert->guid, 'message' => 'Recordatorio'],
            'scheduled_at' => Carbon::parse('2030-06-10 11:30:00', 'UTC'),
            'status' => 'dispatched',
            'require_confirmation' => false,
            'vet_id' => $this->vet->id,
        ]);
        $alert->subject()->associate($this->program);
        $alert->save();

        return [$alert, $this->whatsappRecipient($alert, $this->owner)];
    }

    private function whatsappRecipient(Alert $alert, UserProfile $profile): AlertRecipient
    {
        Contact::firstOrCreate(
            ['contactable_type' => 'user_profile', 'contactable_id' => $profile->id, 'type' => ContactType::Whatsapp],
            ['value' => '5491122334455', 'is_primary' => true, 'use_for_alerts' => true],
        );

        return AlertRecipient::firstOrCreate(
            ['alert_id' => $alert->id, 'user_profile_id' => $profile->id, 'channel' => Channel::Whatsapp],
            ['status' => DeliveryStatus::Pending, 'idempotency_key' => Str::uuid()->toString()],
        );
    }

    private function runJob(AlertRecipient $recipient, ?AlertMessageBuilder $realBuilder = null): FakeGateway
    {
        $gateway = new FakeGateway();
        $gateway->willReturn(DeliveryResult::sent('SM1'));
        app()->instance(FakeGateway::class, $gateway);

        $builder = new class implements AlertMessageBuilder {
            public function type(): AlertType
            {
                return AlertType::ProgramTaskDue;
            }

            public function build(Alert $alert, Recipient $recipient): ?MessageContent
            {
                return new TextContent('hola');
            }
        };

        (new DeliverAlertJob($recipient->id))->handle(
            new MessageBuilderRegistry([$realBuilder ?? $builder]),
            new GatewayRegistry(app(), ['whatsapp' => ['gateway' => FakeGateway::class]]),
            new DeliveryPipeline([]),
            new ChannelFallbackService(),
        );

        return $gateway;
    }

    private function assertSuppressed(AlertRecipient $recipient): void
    {
        $recipient->refresh();
        $this->assertSame(DeliveryStatus::Suppressed, $recipient->status);
        $this->assertSame('no_longer_applicable', $recipient->failure_reason);
        $this->assertSame(FailureCode::NoLongerApplicable, $recipient->failure_code);
    }

    private function profile(Client $client, Role $role): UserProfile
    {
        return UserProfile::create([
            'guid' => Str::uuid()->toString(), 'user_id' => User::factory()->create()->id,
            'authenticatable_type' => 'client', 'authenticatable_id' => $client->id, 'role_id' => $role->id,
        ]);
    }
}

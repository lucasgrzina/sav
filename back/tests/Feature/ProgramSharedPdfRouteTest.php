<?php

namespace Tests\Feature;

use App\Enums\ExportFormat;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Models\Export;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DEC-08: la ruta pública firmada que Twilio usa para descargar el PDF compartido.
 * Sin auth:sanctum a propósito — la firma temporal de Laravel es el control de acceso.
 */
class ProgramSharedPdfRouteTest extends TestCase
{
    use RefreshDatabase;

    private Export $export;
    private string $filePath;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->filePath = 'exports/' . $user->guid . '/shared-pdf-test.pdf';

        Storage::disk('local')->put($this->filePath, '%PDF-1.4 fake content');

        $this->export = Export::create([
            'guid' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'type' => ExportType::PROGRAM,
            'format' => ExportFormat::PDF,
            'status' => ExportStatus::COMPLETED,
            'file_path' => $this->filePath,
            'file_name' => 'program.pdf',
            'expires_at' => now()->addDays(7),
        ]);
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->delete($this->filePath);

        parent::tearDown();
    }

    public function test_returns_the_pdf_with_a_valid_signature(): void
    {
        $signedUrl = URL::temporarySignedRoute(
            'programs.shared-pdf',
            now()->addHours(24),
            ['guid' => $this->export->guid],
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_rejects_an_invalid_signature(): void
    {
        $response = $this->get("/api/v1/programs/shared-pdf/{$this->export->guid}");

        $response->assertStatus(403);
    }

    public function test_rejects_an_expired_signature(): void
    {
        $signedUrl = URL::temporarySignedRoute(
            'programs.shared-pdf',
            now()->subHour(),
            ['guid' => $this->export->guid],
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(403);
    }
}

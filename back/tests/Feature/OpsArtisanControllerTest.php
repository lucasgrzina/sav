<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpsArtisanControllerTest extends TestCase
{
    private const URL = '/_ops/artisan/view-clear';

    public function test_it_is_disabled_when_no_token_is_configured(): void
    {
        config(['ops.artisan_token' => null]);

        $this->postJson(self::URL)->assertNotFound();
        $this->withToken('')->postJson(self::URL)->assertNotFound();
    }

    public function test_it_rejects_a_wrong_token(): void
    {
        config(['ops.artisan_token' => 'secret-token']);

        $this->withToken('wrong')->postJson(self::URL)->assertNotFound();
    }

    public function test_it_rejects_get_requests(): void
    {
        config(['ops.artisan_token' => 'secret-token']);

        $this->withToken('secret-token')->getJson(self::URL)->assertStatus(405);
    }

    public function test_it_rejects_commands_outside_the_whitelist(): void
    {
        config(['ops.artisan_token' => 'secret-token']);

        $this->withToken('secret-token')->postJson('/_ops/artisan/migrate')->assertNotFound();
        $this->withToken('secret-token')->postJson('/_ops/artisan/migrate:fresh')->assertNotFound();
    }

    public function test_it_runs_a_whitelisted_command_with_the_right_token(): void
    {
        config(['ops.artisan_token' => 'secret-token']);

        $this->withToken('secret-token')->postJson(self::URL)
            ->assertOk()
            ->assertJson(['command' => 'view:clear', 'exit_code' => 0]);
    }
}

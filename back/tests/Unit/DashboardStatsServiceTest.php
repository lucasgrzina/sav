<?php

namespace Tests\Unit;

use App\Contracts\Repositories\DashboardStatsRepositoryInterface;
use App\Services\DashboardStatsService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class DashboardStatsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function repo(): Mockery\MockInterface
    {
        $repo = Mockery::mock(DashboardStatsRepositoryInterface::class);
        $repo->shouldReceive('vetCounts')->andReturn(['active' => 2, 'suspended' => 1, 'total' => 4])->byDefault();
        $repo->shouldReceive('platformTotals')->andReturn(
            ['clients' => 3, 'establishments' => 4, 'animals' => 5, 'active_programs' => 6],
        )->byDefault();
        $repo->shouldReceive('sentPerDay')->andReturn([])->byDefault();
        $repo->shouldReceive('deliveryByChannel')->andReturn([])->byDefault();
        $repo->shouldReceive('pendingVets')->andReturn(1)->byDefault();

        return $repo;
    }

    public function test_computes_rates_per_channel_and_overall(): void
    {
        $repo = $this->repo();
        $repo->shouldReceive('deliveryByChannel')->andReturn([
            'whatsapp' => ['ok' => 2, 'failed' => 1, 'sent' => 0],
            'email'    => ['ok' => 0, 'failed' => 0, 'sent' => 1],
        ]);

        $data = (new DashboardStatsService($repo))->forAdmin();

        $this->assertSame(66.7, $data['delivery_30d']['by_channel'][0]['rate']);
        $this->assertSame(0.0, $data['delivery_30d']['by_channel'][1]['rate']);
        $this->assertSame(50.0, $data['delivery_30d']['rate']);
    }

    public function test_rate_is_null_without_attempts_and_days_are_zero_filled(): void
    {
        $data = (new DashboardStatsService($this->repo()))->forAdmin();

        $this->assertNull($data['delivery_30d']['rate']);
        $this->assertCount(7, $data['sent_last_7d']);
        $this->assertSame(0, array_sum(array_column($data['sent_last_7d'], 'count')));
    }

    public function test_second_call_hits_cache_but_pending_vets_is_always_live(): void
    {
        $repo = $this->repo();
        $repo->shouldReceive('vetCounts')->once()->andReturn(['active' => 2, 'suspended' => 1, 'total' => 4]);
        $repo->shouldReceive('pendingVets')->twice()->andReturn(3, 2);

        $service = new DashboardStatsService($repo);

        $this->assertSame(3, $service->forAdmin()['vets']['pending']);
        $this->assertSame(2, $service->forAdmin()['vets']['pending']);
    }
}

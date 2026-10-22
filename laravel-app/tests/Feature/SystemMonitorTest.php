<?php

namespace Tests\Feature;

use App\Services\MarketDataService;
use App\Services\SystemMonitorService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SystemMonitorTest extends TestCase
{
    private function monitor(array $bars = [], ?array $processes = null): SystemMonitorService
    {
        Redis::shouldReceive('get')->with('orderflow:engine:heartbeat')->andReturn(null)->byDefault();
        config(['clusterdelta.instrument' => 'GC', 'monitor.export_directory' => '/tmp/fx-monitor-no-exports-'.bin2hex(random_bytes(8))]);
        $market = \Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->andReturnUsing(fn ($tf) => $bars[$tf] ?? []);
        return new class($market, $processes) extends SystemMonitorService {
            public function __construct(MarketDataService $market, private ?array $snapshot) { parent::__construct($market); }
            protected function processes(): ?array { return $this->snapshot; }
        };
    }

    public function test_recent_arrivals_show_activity_when_processes_are_not_visible(): void
    {
        Redis::shouldReceive('ping')->once()->andReturn(true);
        $monitor = $this->monitor(['15m' => [json_encode(['received_at' => now()->timestamp - 5])]]);
        $status = $monitor->status();
        $services = array_column($status['services'], null, 'id');
        $this->assertTrue($status['healthy']);
        $this->assertSame('active', $services['forwarder']['state']);
        $this->assertSame('unknown', $services['mt5']['state']);
        $this->assertSame([], $services['forwarder']['pids']);
        $this->assertTrue($status['feeds'][0]['receiving']);
    }

    public function test_running_process_with_stale_data_is_not_a_healthy_feed(): void
    {
        Redis::shouldReceive('ping')->once()->andReturn(true);
        $monitor = $this->monitor(['15m' => [json_encode(['received_at' => now()->subMinutes(5)->timestamp])]],
            ['mt5' => [123], 'forwarder' => [456], 'reverb' => []]);
        $status = $monitor->status();
        $services = array_column($status['services'], null, 'id');
        $this->assertFalse($status['healthy']);
        $this->assertFalse($status['feeds'][0]['receiving']);
        $this->assertSame('running', $services['forwarder']['state']);
        $this->assertSame([456], $services['forwarder']['pids']);
        $this->assertStringContainsString('No recent arrivals', $services['forwarder']['detail']);
    }

    public function test_redis_failure_does_not_hide_the_rest_of_the_monitor(): void
    {
        Redis::shouldReceive('ping')->once()->andThrow(new \RuntimeException('Storage unavailable'));
        $status = $this->monitor()->status();
        $services = array_column($status['services'], null, 'id');
        $this->assertFalse($status['healthy']);
        $this->assertSame('unavailable', $services['redis']['state']);
        $this->assertSame('running', $services['app']['state']);
        $this->assertStringContainsString('Redis is unavailable', $status['summary']);
    }

    public function test_monitor_page_and_uncached_status_endpoint_are_available(): void
    {
        $monitor = \Mockery::mock(SystemMonitorService::class);
        $monitor->shouldReceive('status')->once()->andReturn(['healthy' => false, 'services' => [], 'feeds' => []]);
        $this->app->instance(SystemMonitorService::class, $monitor);
        $this->get('/monitor')->assertOk()->assertSee('System Monitor')->assertSee('Data forwarder');
        $this->getJson('/api/system-status')->assertOk()->assertJsonPath('healthy', false)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_detector_card_uses_a_recent_evaluation_heartbeat(): void
    {
        Redis::shouldReceive('ping')->andReturn(true);
        $monitor = $this->monitor();
        Redis::shouldReceive('get')->with('orderflow:engine:heartbeat')->andReturn(json_encode([
            'checked_at' => now()->timestamp - 5, 'error' => false,
        ]));
        $services = array_column($monitor->status()['services'], null, 'id');
        $this->assertSame('active', $services['engine']['state']);
        $this->assertStringContainsString('Recent evaluation', $services['engine']['detail']);
    }
}

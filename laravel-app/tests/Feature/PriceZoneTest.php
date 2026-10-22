<?php

namespace Tests\Feature;

use App\Models\AlertLog;
use App\Models\PriceZone;
use App\Services\AlertDispatchService;
use App\Services\MarketDataService;
use App\Services\PriceZoneDetector;
use App\Services\PriceZoneService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class PriceZoneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 06:30:00', 'UTC'));
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'price_zones.version'=>'wick-reversal-v1',
            'orderflow.connector' => 'clusterdelta', 'clusterdelta.instrument' => 'GC']);
        DB::purge('sqlite'); Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $alerter = \Mockery::mock(AlertDispatchService::class); $alerter->shouldNotReceive('dispatch');
        $this->app->instance(AlertDispatchService::class, $alerter);
    }

    private function history(): array
    {
        $start = intdiv(now()->timestamp, 3600)*3600 - 16*3600;
        $bars = [];
        for ($i=0; $i<14; $i++) $bars[] = ['time'=>$start+$i*3600,'open'=>100,'high'=>101,'low'=>99,'close'=>100,'closed'=>true];
        $bars[] = ['time'=>$start+14*3600,'open'=>100,'high'=>101,'low'=>97,'close'=>99,'closed'=>true];
        $bars[] = ['time'=>$start+15*3600,'open'=>99,'high'=>105,'low'=>98,'close'=>104,'closed'=>true];
        return $bars;
    }

    private function service(array $history, ?array $live = null): PriceZoneService
    {
        Redis::shouldReceive('setex')->andReturn(true);
        $market = \Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->with('1h', 500)->andReturn(array_map(fn($b)=>json_encode($b),array_reverse($history)));
        $market->shouldReceive('storedBars')->andReturnUsing(fn($tf,$limit) => $tf==='15m'&&$live ? [json_encode($live)] : []);
        return new PriceZoneService($market, app(PriceZoneDetector::class));
    }

    private function live(float $price, int $offset = 0): array
    {
        return ['time'=>intdiv(now()->timestamp,900)*900,'closed'=>false,'close'=>$price,
            'price_source'=>'mt5_broker','received_at'=>now()->timestamp+$offset];
    }

    public function test_demand_and_supply_bounds_are_only_the_rejection_wick_to_body(): void
    {
        $detector=app(PriceZoneDetector::class); $bars=$this->history();
        $zones=$detector->detect($bars,now()->timestamp);
        $this->assertCount(1,$zones);$this->assertSame('DEMAND',$zones[0]['type']);
        $this->assertEquals(97,$zones[0]['low']);$this->assertEquals(99,$zones[0]['high']);
        $this->assertSame($bars[15]['time']+3600,$zones[0]['confirmed_time']);
        $inverse=array_map(fn($b)=>array_merge($b,['open'=>200-$b['open'],'close'=>200-$b['close'],
            'high'=>200-$b['low'],'low'=>200-$b['high']]),$bars);
        $supply=$detector->detect($inverse,now()->timestamp);
        $this->assertCount(1,$supply);$this->assertSame('SUPPLY',$supply[0]['type']);
        $this->assertEquals(101,$supply[0]['low']);$this->assertEquals(103,$supply[0]['high']);
    }

    public function test_confirmation_never_uses_future_developing_or_missing_hour_candles(): void
    {
        $detector=app(PriceZoneDetector::class);$bars=$this->history();
        $this->assertSame([],$detector->detect($bars,$bars[15]['time']+3599));
        $bars[15]['closed']=false;$this->assertSame([],$detector->detect($bars,now()->timestamp));
        $bars[15]['closed']=true;$bars[15]['time']+=3600;
        $this->assertSame([],$detector->detect($bars,now()->timestamp+3600));
        $bars=$this->history();$bars[14]['low']=98.9; // wick is too small to count as rejection
        $this->assertSame([],$detector->detect($bars,now()->timestamp));
        $bars=$this->history();$bars[15]['close']=100.5; // fails major departure and structure break
        $this->assertSame([],$detector->detect($bars,now()->timestamp));
    }

    public function test_repeated_scans_save_one_zone_and_no_alert_when_price_is_outside(): void
    {
        $createdStatuses = [];
        PriceZone::created(function ($zone) use (&$createdStatuses) { $createdStatuses[] = $zone->status; });
        $service=$this->service($this->history(),$this->live(104));
        $this->assertSame('watching',$service->run()['status']);$service->run();
        $this->assertSame(['unverified'], $createdStatuses);
        PriceZone::flushEventListeners();
        $this->assertSame(1,PriceZone::count());$this->assertSame('active',PriceZone::first()->status);
        $this->assertSame(0,AlertLog::count());
    }

    public function test_arrival_alert_is_durable_once_per_visit_and_respects_cooldown(): void
    {
        $service=$this->service($this->history(),$this->live(104));$service->run();
        $this->travel(10)->seconds();$service->checkTouches($this->live(98));
        $service->checkTouches($this->live(98));
        $this->assertSame(1,AlertLog::count());
        $this->assertSame('browser',AlertLog::first()->channel);$this->assertSame('ZONE_TOUCH',AlertLog::first()->type);
        $this->assertSame('recorded',AlertLog::first()->status);$this->assertStringContainsString('entered',AlertLog::first()->message);
        $this->travel(10)->seconds();$service->checkTouches($this->live(104));
        $this->travel(10)->seconds();$service->checkTouches($this->live(98));
        $this->assertSame(1,AlertLog::count());
        $this->travel(301)->seconds();$service->checkTouches($this->live(104));
        $this->travel(10)->seconds();$service->checkTouches($this->live(98));
        $this->assertSame(2,AlertLog::count());$this->assertSame(2,PriceZone::first()->entry_count);
    }

    public function test_stale_historical_or_future_prices_cannot_trigger_alerts(): void
    {
        foreach (['stale','historical','future'] as $case) {
            $live=$this->live(98);
            if($case==='stale')$live['received_at']-=120;
            if($case==='historical'){$live['time']-=900;$live['closed']=true;}
            if($case==='future')$live['received_at']+=120;
            $this->assertSame('stale_price',$this->service($this->history(),$live)->run()['status']);
        }
        $this->assertSame(0,AlertLog::count());
    }

    public function test_closed_hour_beyond_far_edge_invalidates_and_blocks_alerts(): void
    {
        $history=$this->history();$this->service($history,$this->live(104))->run();
        $hour=intdiv(now()->timestamp,3600)*3600;$this->travel(1)->hours();
        $history[]=['time'=>$hour,'open'=>104,'high'=>105,'low'=>95,'close'=>96,'closed'=>true];
        $this->service($history,$this->live(98))->run();
        $this->assertSame('invalidated',PriceZone::first()->status);
        $this->assertSame($hour+3600,PriceZone::first()->invalidated_time);
        $this->assertSame(0,AlertLog::count());
    }

    public function test_missing_history_marks_existing_zones_unverified_and_disables_arrivals(): void
    {
        $history=$this->history();$this->service($history,$this->live(104))->run();
        $this->travel(2)->hours();$this->service($history,$this->live(98))->run();
        $this->assertSame('unverified',PriceZone::first()->status);$this->assertSame(0,AlertLog::count());
    }

    public function test_first_observation_inside_is_labelled_honestly_and_notification_cursor_does_not_replay(): void
    {
        $this->service($this->history(),$this->live(98))->run();
        $this->assertStringContainsString('is inside',AlertLog::first()->message);
        $this->getJson('/api/zone-alerts')->assertOk()->assertJsonPath('cursor',1)->assertJsonPath('alerts',[]);
        $this->getJson('/api/zone-alerts?after=0')->assertOk()->assertJsonCount(1,'alerts')->assertJsonPath('alerts.0.id',1);
        $this->getJson('/api/zone-alerts?after=1')->assertOk()->assertJsonPath('alerts',[]);
        $this->getJson('/api/zone-alerts?after=-1')->assertUnprocessable();
        Redis::shouldReceive('get')->andReturn(json_encode(['checked_at'=>now()->timestamp,'reason'=>'Watching','status'=>'watching']));
        $this->getJson('/api/price-zones')->assertOk()->assertJsonPath('monitor_running',true)->assertJsonCount(1,'zones');
        $this->get('/alerts')->assertOk()->assertSee('In-app')->assertSee('ZONE_TOUCH');
    }
}

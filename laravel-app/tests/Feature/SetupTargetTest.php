<?php

namespace Tests\Feature;

use App\Models\Setup;
use App\Services\MarketDataService;
use App\Services\SetupTargetService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SetupTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 08:10:00', 'UTC'));
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
            'orderflow.connector'=>'clusterdelta','clusterdelta.instrument'=>'GC',
            'orderflow.external_alerts_enabled'=>false,'alerts.tp_updates_enabled'=>true,'alerts.sl_updates_enabled'=>true]);
        DB::purge('sqlite');Artisan::call('migrate',['--database'=>'sqlite','--force'=>true]);
    }

    private function setupRow(array $changes = []): Setup
    {
        $setup = Setup::create(array_merge(['direction'=>'BUY','timeframe'=>'15m','entry'=>100,'stop_loss'=>95,
            'tp1'=>110,'tp2'=>120,'score'=>65,'status'=>'active','source'=>'clusterdelta','instrument'=>'GC'],$changes))
            ->forceFill(['created_at'=>\Carbon\Carbon::parse('2026-10-21 07:30:39', 'UTC')]);
        $setup->save();
        return $setup;
    }

    private function bar(string $time, array $changes = []): array
    {
        return array_merge(['time'=>\Carbon\Carbon::parse('2026-10-21 '.$time,'UTC')->timestamp,
            'open'=>100,'high'=>104,'low'=>96,'close'=>100,'closed'=>true,
            'source'=>'clusterdelta','price_source'=>'mt5_broker','received_at'=>now()->timestamp],$changes);
    }

    private function service(array $bars): SetupTargetService
    {
        $market=\Mockery::mock(MarketDataService::class);
        $market->shouldReceive('latestBrokerQuote')->andReturn(null);
        $market->shouldReceive('bars')->with('15m',500)->andReturn(array_map(fn($b)=>json_encode($b),$bars));
        return new SetupTargetService($market);
    }

    private function liveQuote(array $changes=[]): array
    {
        return array_merge(['time'=>\Carbon\Carbon::parse('2026-10-21 08:00:00','UTC')->timestamp,
            'received_at'=>now()->timestamp,'closed'=>false,'source'=>'clusterdelta',
            'price_source'=>'mt5_broker','instrument'=>'GC','timeframe'=>'1h','close'=>121,
            'high'=>130,'low'=>80],$changes);
    }

    public function test_original_m15_setup_can_reach_tp2_from_a_fresh_h1_close_without_importing_its_range(): void
    {
        $setup=$this->setupRow(['tp1_hit_at'=>now()->subMinutes(10)]);
        $market=\Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->with('15m',500)->andReturn([]);
        $market->shouldReceive('latestBrokerQuote')->once()->andReturn($this->liveQuote());
        $service=new SetupTargetService($market);
        $this->assertSame(1,$service->run());$this->assertSame(0,$service->run());
        $setup->refresh();
        $this->assertSame('15m',$setup->timeframe);
        $this->assertNotNull($setup->tp2_hit_at);
        $this->assertNull($setup->sl_hit_at);
        $this->assertSame('sampled_broker_quote',$setup->target_hit_details['tp2']['basis']);
        $this->assertSame('1h',$setup->target_hit_details['tp2']['timeframe']);
        $this->assertSame(121,$setup->target_hit_details['tp2']['observed_price']);
    }

    public function test_cross_timeframe_quote_uses_close_only_and_checks_buy_and_sell_stops(): void
    {
        $setup=$this->setupRow();$service=$this->service([]);
        $this->assertSame([],$service->observedQuoteHits($setup,$this->liveQuote(['close'=>100]),now()->timestamp));
        $hits=$service->observedQuoteHits($setup,$this->liveQuote(['close'=>94]),now()->timestamp);
        $this->assertArrayHasKey('sl',$hits);$this->assertArrayNotHasKey('tp2',$hits);
        $sell=$this->setupRow(['direction'=>'SELL','stop_loss'=>105,'tp1'=>90,'tp2'=>80]);
        $this->assertArrayHasKey('sl',$service->observedQuoteHits($sell,$this->liveQuote(['close'=>106]),now()->timestamp));
        $hits=$service->observedQuoteHits($sell,$this->liveQuote(['close'=>79]),now()->timestamp);
        $this->assertArrayHasKey('tp1',$hits);$this->assertArrayHasKey('tp2',$hits);
    }

    public function test_cross_timeframe_quote_rejects_invalid_receipts_instruments_and_terminal_setups(): void
    {
        $setup=$this->setupRow();$service=$this->service([]);
        foreach ([['instrument'=>'SI'],['price_source'=>'GC_futures'],['source'=>'ctrader'],['closed'=>true],
            ['received_at'=>now()->timestamp-60],['received_at'=>now()->timestamp+60],
            ['time'=>now()->timestamp+60],['time'=>now()->timestamp-7200],['close'=>0],
            ['received_at'=>$setup->created_at->timestamp-1]] as $change) {
            $this->assertSame([],$service->observedQuoteHits($setup,$this->liveQuote($change),now()->timestamp));
        }
        $setup->sl_hit_at=now();
        $this->assertSame([],$service->observedQuoteHits($setup,$this->liveQuote(),now()->timestamp));
        $setup->sl_hit_at=null;$setup->exit_tracking_state='ambiguous';
        $this->assertSame([],$service->observedQuoteHits($setup,$this->liveQuote(),now()->timestamp));
    }

    public function test_target_messages_dispatch_once_per_newly_recorded_target_when_enabled(): void
    {
        config(['orderflow.external_alerts_enabled' => true]);
        $setup = $this->setupRow(['direction' => 'SELL', 'stop_loss' => 105, 'tp1' => 90, 'tp2' => 80]);
        $alerter = \Mockery::mock(\App\Services\AlertDispatchService::class);
        foreach (['TP1_HIT', 'TP2_HIT'] as $type) {
            $alerter->shouldReceive('dispatch')->once()->withArgs(function ($actual, $message, $level, $id) use ($type, $setup) {
                return $actual === $type && $id === $setup->id && $level === 'info'
                    && str_contains($message, '🔴⬇️ XAUUSD SELL')
                    && str_contains($message, substr($type, 0, 3).' reached');
            });
        }
        $this->app->instance(\App\Services\AlertDispatchService::class, $alerter);
        $service = $this->service([$this->bar('07:45:00', ['low' => 79])]);
        $this->assertSame(1, $service->run());
        $this->assertSame(0, $service->run());
        $this->assertNotNull($setup->fresh()->tp2_hit_at);
    }

    public function test_disabled_alerts_still_record_hits_without_dispatching(): void
    {
        $alerter = \Mockery::mock(\App\Services\AlertDispatchService::class);
        $alerter->shouldNotReceive('dispatch');
        $this->app->instance(\App\Services\AlertDispatchService::class, $alerter);
        $setup = $this->setupRow();
        $this->service([$this->bar('07:45:00', ['high' => 121])])->run();
        $this->assertNotNull($setup->fresh()->tp1_hit_at);
        $this->assertNotNull($setup->fresh()->tp2_hit_at);
    }

    public function test_alert_failure_does_not_undo_a_recorded_touch_or_stop_other_target_alerts(): void
    {
        config(['orderflow.external_alerts_enabled' => true]);
        $alerter = \Mockery::mock(\App\Services\AlertDispatchService::class);
        $alerter->shouldReceive('dispatch')->twice()->andThrow(new \RuntimeException('Transport unavailable'));
        $this->app->instance(\App\Services\AlertDispatchService::class, $alerter);
        $setup = $this->setupRow();
        $service = $this->service([$this->bar('07:45:00', ['high' => 121])]);
        $this->assertSame(1, $service->run());
        $this->assertSame(0, $service->run());
        $this->assertNotNull($setup->fresh()->tp1_hit_at);
        $this->assertNotNull($setup->fresh()->tp2_hit_at);
    }

    public function test_buy_and_sell_stops_are_recorded_once_and_end_further_target_tracking(): void
    {
        foreach (['BUY', 'SELL'] as $direction) {
            $setup = $this->setupRow($direction === 'BUY' ? [] : ['direction'=>'SELL','stop_loss'=>105,'tp1'=>90,'tp2'=>80]);
            $service = $this->service([$this->bar('07:45:00', ['low'=>94,'high'=>106])]);
            $this->assertSame(1, $service->run());
            $this->assertSame(0, $service->run());
            $setup->refresh();
            $this->assertNotNull($setup->sl_hit_at);
            $this->assertSame('sl', $setup->exit_tracking_state);
            $this->assertSame('2026-10-21 08:00:00', $setup->sl_hit_at->format('Y-m-d H:i:s'));
            $this->assertNull($setup->tp1_hit_at);
            $this->service([$this->bar('08:00:00', ['closed'=>false,'high'=>130,'low'=>70])])->run();
            $this->assertNull($setup->fresh()->tp2_hit_at);
        }
    }

    public function test_a_later_stop_preserves_an_earlier_tp1_without_reporting_later_tp2(): void
    {
        $setup = $this->setupRow();
        $service = $this->service([
            $this->bar('07:45:00', ['high'=>111]),
            $this->bar('08:00:00', ['closed'=>false,'low'=>94,'high'=>105,'close'=>94]),
        ]);
        $service->run();$setup->refresh();
        $this->assertNotNull($setup->tp1_hit_at);
        $this->assertNotNull($setup->sl_hit_at);
        $this->assertNull($setup->tp2_hit_at);
        $this->assertSame('sl', $setup->exit_tracking_state);
    }

    public function test_stop_and_target_in_one_candle_pause_tracking_without_claiming_an_order_of_hits(): void
    {
        $setup = $this->setupRow();
        $this->service([$this->bar('07:45:00', ['high'=>121,'low'=>94])])->run();
        $setup->refresh();
        $this->assertSame('ambiguous', $setup->exit_tracking_state);
        $this->assertNull($setup->sl_hit_at);
        $this->assertNull($setup->tp1_hit_at);
        $this->assertSame('both_stop_and_target_in_candle', $setup->target_hit_details['path_ambiguous']['basis']);
        $this->assertSame(0, $this->service([$this->bar('08:00:00', ['closed'=>false,'high'=>130])])->run());
        $this->assertNull($setup->fresh()->tp2_hit_at);
    }

    public function test_prior_tp1_does_not_resolve_stop_and_tp2_order_in_a_later_candle(): void
    {
        $setup = $this->setupRow();
        $this->service([$this->bar('07:45:00', ['high'=>111]),
            $this->bar('08:00:00', ['closed'=>false,'high'=>121,'low'=>94])])->run();
        $setup->refresh();
        $this->assertNotNull($setup->tp1_hit_at);
        $this->assertSame('ambiguous', $setup->exit_tracking_state);
        $this->assertNull($setup->sl_hit_at);
        $this->assertNull($setup->tp2_hit_at);
    }

    public function test_stop_ignores_pre_creation_wicks_and_stale_wrong_source_or_future_data(): void
    {
        $setup = $this->setupRow();
        $this->service([$this->bar('07:15:00', ['low'=>90]),
            $this->bar('07:30:00', ['low'=>90,'close'=>100])])->run();
        foreach ([['received_at'=>now()->timestamp-120],['received_at'=>now()->timestamp+120],
            ['price_source'=>'GC_futures'],['source'=>'ctrader'],['time'=>now()->timestamp+900]] as $changes) {
            $this->service([$this->bar('08:00:00', array_merge(['closed'=>false,'low'=>90,'close'=>92], $changes))])->run();
        }
        $this->assertNull($setup->fresh()->sl_hit_at);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 07:40:00','UTC'));
        $this->service([$this->bar('07:30:00', ['closed'=>false,'low'=>90,'high'=>104,'close'=>94])])->run();
        $this->assertNotNull($setup->fresh()->sl_hit_at);
        $this->assertSame('sampled_close', $setup->fresh()->target_hit_details['sl']['basis']);
    }

    public function test_tp2_completion_prevents_a_later_stop_alert(): void
    {
        $setup = $this->setupRow();
        $this->service([$this->bar('07:45:00', ['high'=>121]),
            $this->bar('08:00:00', ['closed'=>false,'low'=>90,'close'=>92])])->run();
        $setup->refresh();
        $this->assertNotNull($setup->tp2_hit_at);
        $this->assertNull($setup->sl_hit_at);
        $this->assertSame('tp2', $setup->exit_tracking_state);
    }

    public function test_stop_alert_obeys_its_switch_and_is_dispatched_once(): void
    {
        config(['orderflow.external_alerts_enabled'=>true]);
        $alerter = \Mockery::mock(\App\Services\AlertDispatchService::class);
        $alerter->shouldReceive('dispatch')->once()->withArgs(fn ($type,$message,$level,$id) =>
            $type === 'SL_HIT' && str_contains($message,'SL PRICE REACHED') && str_contains($message,'🟢⬆️ XAUUSD BUY'));
        $this->app->instance(\App\Services\AlertDispatchService::class,$alerter);
        $setup = $this->setupRow();
        $service = $this->service([$this->bar('07:45:00',['low'=>94])]);
        $service->run();$service->run();
        config(['alerts.sl_updates_enabled'=>false]);
        $other = $this->setupRow();$service->run();
        $this->assertNotNull($other->fresh()->sl_hit_at);
    }

    public function test_target_switch_suppresses_messages_without_disabling_tracking(): void
    {
        config(['orderflow.external_alerts_enabled'=>true,'alerts.tp_updates_enabled'=>false]);
        $alerter = \Mockery::mock(\App\Services\AlertDispatchService::class);$alerter->shouldNotReceive('dispatch');
        $this->app->instance(\App\Services\AlertDispatchService::class,$alerter);
        $setup = $this->setupRow();$this->service([$this->bar('07:45:00',['high'=>121])])->run();
        $this->assertNotNull($setup->fresh()->tp2_hit_at);
    }

    public function test_buy_targets_are_independent_and_remain_recorded_after_retracement_and_repeated_scans(): void
    {
        $setup=$this->setupRow();
        $first=$this->service([$this->bar('07:45:00',['high'=>111,'close'=>103])]);
        $this->assertSame(1,$first->run());$this->assertSame(0,$first->run());
        $setup->refresh();$this->assertNotNull($setup->tp1_hit_at);$this->assertNull($setup->tp2_hit_at);
        $this->assertSame('2026-10-21 08:00:00',$setup->tp1_hit_at->format('Y-m-d H:i:s'));
        $this->assertSame('post_setup_candle_range',$setup->target_hit_details['tp1']['basis']);
        $timestamp=$setup->tp1_hit_at->timestamp;
        $this->service([$this->bar('08:00:00',['closed'=>false,'high'=>121,'close'=>115])])->run();
        $setup->refresh();$this->assertNotNull($setup->tp2_hit_at);$this->assertSame($timestamp,$setup->tp1_hit_at->timestamp);
        $this->service([$this->bar('08:00:00',['closed'=>false,'high'=>104,'close'=>101])])->run();
        $setup->refresh();$this->assertNotNull($setup->tp1_hit_at);$this->assertNotNull($setup->tp2_hit_at);
    }

    public function test_sell_targets_use_lows_and_a_move_beyond_tp2_records_both_targets(): void
    {
        $setup=$this->setupRow(['direction'=>'SELL','stop_loss'=>105,'tp1'=>90,'tp2'=>80]);
        $this->service([$this->bar('07:45:00',['low'=>79,'close'=>97])])->run();
        $setup->refresh();$this->assertNotNull($setup->tp1_hit_at);$this->assertNotNull($setup->tp2_hit_at);
        $this->assertEquals(79,$setup->target_hit_details['tp2']['observed_price']);
    }

    public function test_pre_setup_and_overlapping_candle_wicks_do_not_mark_a_target(): void
    {
        $setup=$this->setupRow();
        $this->service([$this->bar('07:15:00',['high'=>130]),$this->bar('07:30:00',['high'=>130,'close'=>101])])->run();
        $setup->refresh();$this->assertNull($setup->tp1_hit_at);$this->assertNull($setup->tp2_hit_at);
    }

    public function test_a_close_observed_after_creation_can_qualify_on_the_overlapping_candle(): void
    {
        $setup=$this->setupRow();
        $this->service([$this->bar('07:30:00',['high'=>130,'close'=>112])])->run();
        $setup->refresh();$this->assertNotNull($setup->tp1_hit_at);$this->assertNull($setup->tp2_hit_at);
        $this->assertSame('post_setup_candle_close',$setup->target_hit_details['tp1']['basis']);
    }

    public function test_stale_future_or_wrong_source_live_data_cannot_create_hits(): void
    {
        $setup=$this->setupRow();
        foreach ([['received_at'=>now()->timestamp-120],['received_at'=>now()->timestamp+120],
            ['price_source'=>'GC_futures'],['source'=>'ctrader'],['time'=>now()->timestamp+900]] as $changes) {
            $this->service([$this->bar('08:00:00',array_merge(['closed'=>false,'high'=>130,'close'=>125],$changes))])->run();
        }
        $setup->refresh();$this->assertNull($setup->tp1_hit_at);$this->assertNull($setup->tp2_hit_at);
    }

    public function test_live_creation_candle_counts_only_its_new_close_not_earlier_wicks(): void
    {
        $setup=$this->setupRow();$this->travelTo(\Carbon\Carbon::parse('2026-10-21 07:40:00','UTC'));
        $this->service([$this->bar('07:30:00',['closed'=>false,'high'=>125,'close'=>105])])->run();
        $this->assertNull($setup->fresh()->tp1_hit_at);
        $this->travel(10)->seconds();
        $this->service([$this->bar('07:30:00',['closed'=>false,'high'=>125,'close'=>111])])->run();
        $setup->refresh();$this->assertNotNull($setup->tp1_hit_at);$this->assertNull($setup->tp2_hit_at);
        $this->assertSame('sampled_close',$setup->target_hit_details['tp1']['basis']);
    }

    public function test_only_the_matching_instrument_source_and_open_setup_are_tracked(): void
    {
        $other=$this->setupRow(['instrument'=>'SI']);$closed=$this->setupRow(['status'=>'closed']);
        $this->assertSame(0,$this->service([$this->bar('07:45:00',['high'=>130])])->run());
        $this->assertNull($other->fresh()->tp1_hit_at);$this->assertNull($closed->fresh()->tp1_hit_at);
    }

    public function test_dashboard_renders_persisted_hits_and_api_retains_their_evidence(): void
    {
        $setup=$this->setupRow();$this->service([$this->bar('07:45:00',['high'=>111])])->run();
        $market=\Mockery::mock(MarketDataService::class);
        $market->shouldReceive('activeTimeframe')->andReturn('15m');$market->shouldReceive('bars')->andReturn([]);
        $this->app->instance(MarketDataService::class,$market);Redis::shouldReceive('get')->andReturn(null);
        $this->getJson('/api/setups/'.$setup->id)->assertOk()->assertJsonPath('tp2_hit_at',null)
            ->assertJsonPath('target_hit_details.tp1.basis','post_setup_candle_range');
        $this->get('/')->assertOk()->assertSee('metric-pill tp-card tp-hit',false)->assertSee('TP1 price reached')
            ->assertSee('TP2 waiting for price')->assertSee('prefers-reduced-motion');
    }
}

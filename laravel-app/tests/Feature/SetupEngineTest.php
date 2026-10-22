<?php

namespace Tests\Feature;

use App\Models\Setup;
use App\Models\Signal;
use App\Services\AlertDispatchService;
use App\Services\FootprintService;
use App\Services\MarketDataService;
use App\Services\OrderflowMetricsService;
use App\Services\SetupBuilderService;
use App\Services\SetupEngineService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SetupEngineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Never run these persistence fixtures against the user's market database.
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'orderflow.connector' => 'clusterdelta', 'clusterdelta.instrument' => 'GC',
            'orderflow.min_confluence_score' => 60, 'orderflow.delta_threshold' => 500,
            'orderflow.setup_strategy' => 'weighted_orderflow_v1',
            'orderflow.broadcast_enabled' => false, 'orderflow.external_alerts_enabled' => false]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
        $alerter = \Mockery::mock(AlertDispatchService::class);
        $alerter->shouldNotReceive('dispatch');
        $this->app->instance(AlertDispatchService::class, $alerter);
    }

    private function metrics(array $overrides = []): array
    {
        return array_merge(['source' => 'clusterdelta', 'instrument' => 'GC', 'timeframe' => '15m',
            'bar_time' => intdiv(now()->timestamp, 900) * 900 - 900, 'closed' => true,
            'received_at' => now()->timestamp, 'bar_count' => 20,
            'price' => 2000, 'open' => 1999, 'high' => 2002, 'low' => 1998, 'close' => 2000,
            'delta' => 800, 'buy_volume' => 1000, 'sell_volume' => 200, 'cvd' => 1000,
            'vwap' => 1980, 'poc' => 1980, 'cvd_divergence' => 'BULLISH_DIVERGENCE',
            'absorption' => 'BULL_ABSORPTION', 'stacked_imbalance' => 'BULLISH',
            'exhaustion' => 'BULLISH_EXHAUSTION', 'poc_rejection' => 'BULLISH',
            'footprint_previous_poc' => 2010,
            'participation' => ['bar_time'=>intdiv(now()->timestamp,900)*900-900,
                'state'=>'NORMAL','volume'=>1200,'baseline_volume'=>1000,'ratio'=>1.2,
                'baseline_kind'=>'rolling_median','baseline_sample_size'=>20,'baseline_lookback'=>20,
                'flow'=>'BUY_DOMINANT','delta_share'=>0.6667,'session_start'=>now()->timestamp-3600,
                'session_timezone'=>'America/New_York','session_first_observed'=>now()->timestamp-3600,
                'session_coverage'=>'COMPLETE','session_bar_count'=>4,'session_volume'=>4000,'session_delta'=>800]], $overrides);
    }

    public function test_new_setup_dispatches_the_entry_template_once_even_after_repeat_evaluation(): void
    {
        config(['orderflow.external_alerts_enabled' => true]);
        $alerter = \Mockery::mock(AlertDispatchService::class);
        $alerter->shouldReceive('dispatch')->once()->withArgs(function ($type, $message, $level, $id) {
            return $type === 'NEW_SETUP' && $level === 'buy' && $id > 0
                && str_contains($message, '<b>Buy opportunity</b>')
                && str_contains($message, 'Delta:') && str_contains($message, 'Confluence:')
                && str_contains($message, 'SL:') && str_contains($message, 'TP1:')
                && str_contains($message, 'TP2:') && !str_contains($message, 'ENTRY SIGNAL');
        });
        $this->app->instance(AlertDispatchService::class, $alerter);
        $builder = app(SetupBuilderService::class);
        $setup = $builder->evaluate($this->metrics());
        $this->assertNotNull($setup);
        $this->assertSame($setup->id, $builder->evaluate($this->metrics())->id);
    }

    public function test_opposing_conditions_do_not_combine_to_create_a_setup(): void
    {
        $builder = app(SetupBuilderService::class);
        $m = $this->metrics(['cvd_divergence' => 'BEARISH_DIVERGENCE',
            'stacked_imbalance' => 'BEARISH', 'exhaustion' => 'BEARISH_EXHAUSTION']);
        $assessment = $builder->assess($m);
        $this->assertEquals(50, $assessment['bull_score']);
        $this->assertEquals(50, $assessment['bear_score']);
        $this->assertNull($builder->evaluate($m));
        $mixed = $this->metrics(['absorption' => 'NONE', 'stacked_imbalance' => 'BEARISH',
            'exhaustion' => 'BEARISH_EXHAUSTION', 'vwap' => 2000, 'poc_rejection' => 'BEARISH']);
        $this->assertEquals(40, $builder->assess($mixed)['bull_score']);
        $this->assertEquals(35, $builder->assess($mixed)['bear_score']);
        $this->assertNull($builder->evaluate($mixed)); // total 75 does not qualify either direction
        $this->assertSame(0, Setup::count());
    }

    public function test_qualifying_setup_and_signal_details_persist_once_even_after_invalidation(): void
    {
        $builder = app(SetupBuilderService::class);
        $setup = $builder->evaluate($this->metrics());
        $this->assertSame(100, $setup->score);
        $this->assertSame('BUY', $setup->direction);
        $this->assertCount(8, $setup->fresh()->confluence_detail);
        $this->assertSame(35.0, $setup->risk_pips);
        $this->assertEquals(1.5, $setup->rr_ratio);
        $this->assertSame(7, $setup->signals()->count());
        $this->assertSame(100, (int) $setup->signals()->sum('weight'));
        $this->assertSame($this->metrics()['bar_time'], $setup->signals()->first()->meta['candle_time']);
        $setup->update(['status' => 'invalidated']);
        $same = $builder->evaluate($this->metrics());
        $this->assertSame($setup->id, $same->id);
        $this->assertFalse($same->wasRecentlyCreated);
        $this->assertSame(1, Setup::count());
        $this->assertSame(7, Signal::count());
    }

    public function test_signal_failure_rolls_back_the_whole_setup(): void
    {
        Signal::creating(function () { throw new \RuntimeException('Signal write failed'); });
        try {
            app(SetupBuilderService::class)->evaluate($this->metrics());
            $this->fail('Expected the signal failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Signal write failed', $e->getMessage());
        } finally { Signal::flushEventListeners(); }
        $this->assertSame(0, Setup::count());
        $this->assertSame(0, Signal::count());
    }

    private function engine(array $m, ?array $snapshot, int $feedAge = 0): SetupEngineService
    {
        Redis::shouldReceive('setex')->andReturn(true);
        $market = \Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->with('15m', 1)->andReturn([json_encode(['received_at' => now()->timestamp - $feedAge])]);
        $metrics = \Mockery::mock(OrderflowMetricsService::class);
        $metrics->shouldReceive('compute')->with('15m', 20, true)->andReturn($m);
        $footprints = \Mockery::mock(FootprintService::class)->makePartial();
        $footprints->shouldReceive('snapshot')->with('GC', '15m')->andReturn($snapshot);
        return new SetupEngineService($market, $metrics, $footprints, app(SetupBuilderService::class));
    }

    private function snapshot(array $m): array
    {
        return ['received_at' => now()->timestamp, 'tick_size' => 0.1, 'bars' => [
            ['time' => $m['bar_time'], 'closed' => true, 'source_totals_match' => true,
                'volume' => $m['buy_volume'] + $m['sell_volume'], 'delta' => $m['delta'],
                'poc' => 2010, 'open' => 2010, 'high' => 2011, 'low' => 2009, 'close' => 2010,
                'levels' => [['price' => 2010, 'ask' => 1000, 'bid' => 200]]]]];
    }

    public function test_stale_feed_short_history_future_and_developing_candles_cannot_trigger(): void
    {
        $cases = [[$this->metrics(), 120], [$this->metrics(['bar_count' => 3]), 0],
            [$this->metrics(['closed' => false]), 0],
            [$this->metrics(['bar_time' => now()->timestamp + 900]), 0],
            [$this->metrics(['bar_time' => now()->timestamp - 3600]), 0]];
        foreach ($cases as [$m, $age]) {
            $result = $this->engine($m, $this->snapshot($m), $age)->run('15m');
            $this->assertSame('blocked', $result['status']);
            $this->assertSame([], $result['signals']);
        }
        $this->assertSame(0, Setup::count());
        $this->assertSame(0, \App\Models\SetupObservation::count());
    }

    public function test_footprints_must_be_fresh_closed_and_match_volume_delta(): void
    {
        $m = $this->metrics();
        foreach (['absent', 'stale', 'wrong_time', 'developing', 'volume', 'delta', 'vendor'] as $case) {
            $snapshot = $this->snapshot($m);
            if ($case === 'absent') $snapshot = null;
            if ($case === 'stale') $snapshot['received_at'] -= 120;
            if ($case === 'wrong_time') $snapshot['bars'][0]['time'] -= 900;
            if ($case === 'developing') $snapshot['bars'][0]['closed'] = false;
            if ($case === 'volume') $snapshot['bars'][0]['volume']++;
            if ($case === 'delta') $snapshot['bars'][0]['delta']++;
            if ($case === 'vendor') $snapshot['bars'][0]['source_totals_match'] = false;
            $result = $this->engine($m, $snapshot)->run('15m');
            $this->assertSame('blocked', $result['status'], $case);
        }
        $this->assertSame(0, Setup::count());
    }

    public function test_native_adjacent_levels_define_stacks_and_futures_poc_rejection(): void
    {
        $m = $this->metrics(); $engine = $this->engine($m, null);
        $bar = ['open' => 2000.1, 'high' => 2000.4, 'low' => 2000, 'close' => 2000.3,
            'levels' => [['price' => 2000, 'ask' => 1, 'bid' => 1],
                ['price' => 2000.1, 'ask' => 30, 'bid' => 1],
                ['price' => 2000.2, 'ask' => 30, 'bid' => 1],
                ['price' => 2000.3, 'ask' => 30, 'bid' => 1]]];
        $features = $engine->footprintFeatures($bar, ['poc' => 2000.1], 0.1);
        $this->assertSame('BULLISH', $features['stacked_imbalance']);
        $this->assertSame('BULLISH', $features['poc_rejection']);
        $bar['levels'][2]['price'] = 2000.5;
        $this->assertSame('NONE', $engine->footprintFeatures($bar, null, 0.1)['stacked_imbalance']);
    }

    public function test_matched_closed_inputs_save_a_setup_without_queue_or_external_alerts(): void
    {
        $m = $this->metrics();
        $engine = $this->engine($m, $this->snapshot($m));
        $state = $engine->run('15m');
        $this->assertSame('detected', $state['status']);
        $this->assertTrue($state['footprint_matched']);
        $this->assertSame(75, $state['bull_score']); // no native stack or previous native POC
        $this->assertSame(1, Setup::count());
        $this->assertSame($state['setup_id'],\App\Models\SetupObservation::first()->setup_id);
        $this->assertSame('recorded', $engine->run('15m')['status']);
        $this->assertSame(1, Setup::count());
        $this->assertSame(1,\App\Models\SetupObservation::count());
        $this->assertSame(1,\App\Models\MarketParticipationSnapshot::count());
    }

    public function test_a_45_point_matched_candle_is_archived_without_creating_a_live_setup(): void
    {
        config(['orderflow.external_alerts_enabled'=>true]);
        $m=$this->metrics(['delta'=>88,'buy_volume'=>100,'sell_volume'=>12,'vwap'=>2000]);
        $snapshot=$this->snapshot($m);
        $snapshot['bars'][0]['levels']=[['price'=>2010,'ask'=>100,'bid'=>12]];
        $engine=$this->engine($m,$snapshot);
        $state=$engine->run('15m');
        $this->assertSame(45,$state['score']);
        $this->assertSame('waiting',$state['status']);
        $this->assertNotNull($state['observation_id']);
        $this->assertSame(0,Setup::count());$this->assertSame(0,Signal::count());
        $record=\App\Models\SetupObservation::first();
        $this->assertSame(45,$record->score);$this->assertNull($record->setup_id);
        $this->assertSame(60,$record->signal_threshold);
        $this->assertSame('BUY',$record->direction);
        $this->assertSame(2000.0,$record->entry);
        $engine->run('15m');$this->assertSame(1,\App\Models\SetupObservation::count());
    }

    public function test_a_40_point_candle_is_evaluated_but_not_archived(): void
    {
        $m=$this->metrics(['absorption'=>'NONE','exhaustion'=>'NONE','vwap'=>2000]);
        $state=$this->engine($m,$this->snapshot($m))->run('15m');
        $this->assertSame(40,$state['score']);$this->assertNull($state['observation_id']);
        $this->assertSame(0,\App\Models\SetupObservation::count());
    }

    public function test_status_reports_stopped_detector_and_export_route_is_not_a_model_id(): void
    {
        Redis::shouldReceive('get')->andReturn(json_encode(['timeframe' => '15m',
            'checked_at' => now()->timestamp - 120, 'status' => 'detected', 'signals' => []]));
        $this->getJson('/api/setups/status')->assertOk()->assertJsonPath('timeframes.0.engine_fresh', false)
            ->assertJsonPath('timeframes.0.status', 'not_running');
        $this->get('/api/setups/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $this->get('/setups')->assertOk()->assertSee('Live setup detector')->assertSee('Scores are rule weights');
    }

    public function test_setup_metrics_exclude_the_developing_bar_and_preserve_provenance(): void
    {
        $time = intdiv(now()->timestamp, 900) * 900;
        $base = ['open' => 2000, 'high' => 2002, 'low' => 1998, 'close' => 2001,
            'buy_vol' => 60, 'sell_vol' => 40, 'delta' => 20, 'received_at' => now()->timestamp];
        $market = \Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->with('15m', 300)->andReturn([
            json_encode(array_merge($base, ['time' => $time, 'closed' => false, 'delta' => 9999])),
            json_encode(array_merge($base, ['time' => $time - 900, 'closed' => true])),
        ]);
        $this->app->instance(MarketDataService::class, $market);
        $result = app(OrderflowMetricsService::class)->compute('15m', 20, true);
        $this->assertSame($time - 900, $result['bar_time']);
        $this->assertSame(20, $result['delta']);
        $this->assertSame(20.0, $result['cvd']);
        $this->assertSame(1, $result['bar_count']);
        $this->assertTrue($result['closed']);
    }
    public function test_screenshot_conditions_explain_waiting_absorption_sequence_and_bullish_exhaustion(): void
    {
        $time = intdiv(now()->timestamp, 900) * 900 - 900;
        $latest = ['time' => $time, 'closed' => true, 'open' => 4116.118, 'high' => 4116.895,
            'low' => 4114.054, 'close' => 4114.721, 'delta' => 22, 'buy_vol' => 262, 'sell_vol' => 240];
        $previous = array_merge($latest, ['time' => $time - 900, 'close' => 4115.795, 'delta' => -118]);
        $market = \Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->with('15m', 300)->andReturn(array_map(fn ($bar) => json_encode($bar),
            [$latest, $previous, array_merge($previous, ['time' => $time - 1800]), array_merge($previous, ['time' => $time - 2700])]));
        $this->app->instance(MarketDataService::class, $market);
        $m = app(OrderflowMetricsService::class)->compute('15m', 20, true);
        $this->assertEqualsWithDelta(0.4917, $m['body_range_ratio'], 0.0001);
        $this->assertSame('NONE', $m['absorption']);
        $this->assertSame('BULLISH_EXHAUSTION', $m['exhaustion']);
        $signals = collect(app(SetupBuilderService::class)->assess($m)['signals'])->keyBy('key');
        $this->assertStringContainsString('completed M5 bars only', $signals['absorption']['detail']);
        $this->assertSame(0, $signals['absorption']['weight']);
        $this->assertSame(15, $signals['absorption']['max_weight']);
        $this->assertStringContainsString('+140', $signals['exhaustion']['detail']);
        $this->assertSame(10, $signals['exhaustion']['weight']);
        $this->assertFalse($signals['poc_rejection']['available']);
    }

    public function test_zero_delta_does_not_fire_the_absorption_sequence(): void
    {
        $market = \Mockery::mock(MarketDataService::class);
        $market->shouldReceive('bars')->andReturn([json_encode(['time' => now()->timestamp - 900, 'closed' => true,
            'open' => 2000, 'high' => 2002, 'low' => 1998, 'close' => 2000, 'delta' => 0, 'buy_vol' => 50, 'sell_vol' => 50])]);
        $this->app->instance(MarketDataService::class, $market);
        $this->assertSame('NONE', app(OrderflowMetricsService::class)->compute('15m', 20, true)['absorption']);
    }

    public function test_dashboard_links_saved_setups_by_source_instrument_timeframe_and_candle(): void
    {
        $m = $this->metrics();
        $setup = app(SetupBuilderService::class)->evaluate($m);
        $market = \Mockery::mock(MarketDataService::class);
        $market->shouldReceive('activeTimeframe')->andReturn('15m');
        $market->shouldReceive('bars')->andReturn([json_encode(array_merge($m, ['time' => $m['bar_time'],
            'buy_vol' => 1000, 'sell_vol' => 200]))]);
        $this->app->instance(MarketDataService::class, $market);
        Redis::shouldReceive('get')->andReturn(null);
        $this->get('/')->assertOk()->assertViewHas('recentBars', fn ($bars) => $bars[0]['signal'] === 'BUY')
            ->assertSee('Bar vol ratio')->assertSee('confluence-signals');
        $setup->update(['source' => 'ctrader']);
        $this->get('/')->assertOk()->assertViewHas('recentBars', fn ($bars) => $bars[0]['signal'] === null);
    }

}

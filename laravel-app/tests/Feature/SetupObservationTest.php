<?php

namespace Tests\Feature;

use App\Models\Setup;
use App\Models\SetupObservation;
use App\Services\SetupObservationService;
use App\Services\MarketDataService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SetupObservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
            'orderflow.connector'=>'clusterdelta','clusterdelta.instrument'=>'GC',
            'orderflow.history_min_score'=>45,'orderflow.min_confluence_score'=>60]);
        DB::purge('sqlite');Artisan::call('migrate',['--database'=>'sqlite','--force'=>true]);
    }

    private function metrics(array $changes=[]): array
    {
        return array_merge(['source'=>'clusterdelta','instrument'=>'GC','timeframe'=>'15m',
            'bar_time'=>now()->subHour()->timestamp,'price'=>2000,'high'=>2002,'low'=>1998,'closed'=>true],$changes);
    }

    private function assessment(int $score=45,array $changes=[]): array
    {
        return array_merge(['score'=>$score,'bull_score'=>$score,'bear_score'=>0,'direction'=>'BUY','threshold'=>60,
            'signals'=>[['key'=>'test_snapshot','name'=>'Recorded condition','status'=>'bullish','weight'=>$score,
                'detail'=>'Frozen observation evidence']]],$changes);
    }

    private function record(int $score=45,array $metrics=[],array $assessment=[]): SetupObservation
    {
        return app(SetupObservationService::class)->record($this->metrics($metrics),$this->assessment($score,$assessment));
    }

    public function test_floor_is_inclusive_and_repeat_checks_preserve_the_first_snapshot(): void
    {
        $service=app(SetupObservationService::class);
        $this->assertNull($service->record($this->metrics(),$this->assessment(44)));
        $record=$this->record();$spotted=$record->spotted_at->toIso8601String();
        $again=$this->record(55,['price'=>2001]);
        $this->assertSame($record->id,$again->id);$this->assertSame(45,$again->score);
        $this->assertSame(2000.0,$again->entry);$this->assertSame($spotted,$again->spotted_at->toIso8601String());
        $this->assertSame(1,SetupObservation::count());$this->assertSame(0,Setup::count());
        $this->assertSame('Frozen observation evidence',$again->confluence_detail[0]['detail']);
    }

    public function test_each_source_instrument_timeframe_and_candle_has_its_own_record(): void
    {
        $m=$this->metrics();$this->record();
        foreach ([['source'=>'other'],['instrument'=>'SI'],['timeframe'=>'1h'],['bar_time'=>$m['bar_time']-900]] as $change) $this->record(45,$change);
        $this->assertSame(5,SetupObservation::count());
    }

    public function test_tied_direction_is_retained_without_directional_trade_levels(): void
    {
        $record=$this->record(45,[],['direction'=>'NEUTRAL','bear_score'=>45]);
        $this->assertSame('NEUTRAL',$record->direction);$this->assertNull($record->entry);
        $this->assertNull($record->tp1);$this->assertNull($record->setup_id);
    }

    public function test_import_uses_existing_evidence_and_is_idempotent_without_dispatching_alerts(): void
    {
        $alerter=\Mockery::mock(\App\Services\AlertDispatchService::class);$alerter->shouldNotReceive('dispatch');
        $this->app->instance(\App\Services\AlertDispatchService::class,$alerter);
        $setup=Setup::create(['source'=>'clusterdelta','instrument'=>'GC','timeframe'=>'15m','candle_time'=>$this->metrics()['bar_time'],
            'direction'=>'BUY','score'=>65,'entry'=>2000,'stop_loss'=>1995,'tp1'=>2007.5,'tp2'=>2015,'status'=>'active',
            'confluence_detail'=>[['status'=>'bullish','weight'=>65,'detail'=>'Original condition']]]);
        $this->artisan('setups:import-history')->assertSuccessful();
        $this->artisan('setups:import-history')->assertSuccessful();
        $record=SetupObservation::sole();
        $this->assertSame($setup->id,$record->setup_id);$this->assertSame(65,$record->score);
        $this->assertSame('saved_setup_import',$record->origin);$this->assertNull($record->metrics);
        $this->assertNull($record->signal_threshold);
        $this->assertSame($setup->created_at->timestamp,$record->spotted_at->timestamp);
    }

    public function test_history_table_filters_keep_only_the_requested_observations(): void
    {
        $buy=$this->record();$sell=$this->record(55,['bar_time'=>now()->subHours(2)->timestamp],['direction'=>'SELL','bull_score'=>0,'bear_score'=>55]);
        $high=$this->record(75,['timeframe'=>'1h']);
        $market=\Mockery::mock(MarketDataService::class);$market->shouldReceive('bars')->andReturn([]);
        $this->app->instance(MarketDataService::class,$market);Redis::shouldReceive('get')->andReturn(null);
        $this->get('/setups?obs_band=below65')->assertOk()->assertViewHas('observations',fn($rows)=>$rows->total()===2)
            ->assertSee('Spotted setup history')->assertSee('Lower scores are not retained.');
        $this->get('/setups?obs_direction=SELL')->assertOk()->assertViewHas('observations',fn($rows)=>$rows->count()===1&&$rows->first()->id===$sell->id);
        $this->get('/setups?obs_timeframe=1h')->assertOk()->assertViewHas('observations',fn($rows)=>$rows->count()===1&&$rows->first()->id===$high->id);
        $this->getJson('/setups?obs_band=invalid')->assertUnprocessable();
    }

    public function test_details_show_saved_conditions_and_do_not_expose_other_feeds_or_below_floor_rows(): void
    {
        $record=$this->record();
        $this->get('/setup-observations/'.$record->id)->assertOk()->assertSee('Frozen observation evidence')
            ->assertSee('Observation only')->assertSee('Live threshold at observation: 60');
        $other=$this->record(45,['instrument'=>'SI']);
        $this->get('/setup-observations/'.$other->id)->assertNotFound();
        $record->update(['score'=>44]);$this->get('/setup-observations/'.$record->id)->assertNotFound();
    }
}

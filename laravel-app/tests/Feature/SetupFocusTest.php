<?php

namespace Tests\Feature;

use App\Models\Setup;
use App\Services\MarketDataService;
use App\Services\SetupTargetService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SetupFocusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default'=>'sqlite','database.connections.sqlite.database'=>':memory:',
            'orderflow.connector'=>'clusterdelta','clusterdelta.instrument'=>'GC']);
        DB::purge('sqlite');Artisan::call('migrate',['--database'=>'sqlite','--force'=>true]);
    }

    private function setupRow(array $changes=[]): Setup
    {
        return Setup::create(array_merge(['direction'=>'BUY','timeframe'=>'15m','entry'=>100,'stop_loss'=>95,
            'tp1'=>110,'tp2'=>120,'score'=>65,'status'=>'active','source'=>'clusterdelta','instrument'=>'GC',
            'confluence_detail'=>[['key'=>'delta_imbalance','name'=>'Saved buy delta','status'=>'bullish','weight'=>20,'detail'=>'Buy snapshot']]],$changes));
    }

    public function test_new_sell_setup_does_not_replace_an_explicitly_selected_buy_setup(): void
    {
        $buy=$this->setupRow(['tp1_hit_at'=>now()]);
        $sell=$this->setupRow(['direction'=>'SELL','entry'=>200,'stop_loss'=>205,'tp1'=>190,'tp2'=>180,
            'confluence_detail'=>[['name'=>'Saved sell delta','status'=>'bearish','weight'=>20,'detail'=>'Sell snapshot']]]);
        $response=$this->get('/setups?setup_id='.$buy->id);
        $response->assertOk()->assertViewHas('focusedSetup',fn($s)=>$s->id===$buy->id)
            ->assertSee('data-focused-id="'.$buy->id.'"',false)->assertSee('TP1 price reached')
            ->assertSee('Saved buy delta')->assertSee('Buy snapshot')->assertDontSee('Sell snapshot');
        $response->assertViewHas('setupChoices',fn($items)=>$items->contains('id',$buy->id)&&$items->contains('id',$sell->id));
        $this->get('/setups')->assertOk()->assertViewHas('focusedSetup',fn($s)=>$s->id===$sell->id);
    }

    public function test_focused_details_and_tp_hit_flags_belong_to_the_requested_id(): void
    {
        $buy=$this->setupRow(['tp1_hit_at'=>now()]);
        $sell=$this->setupRow(['direction'=>'SELL','tp1'=>90,'tp2'=>80]);
        $buyResponse=$this->getJson('/api/setups/'.$buy->id.'/focus')->assertOk()->assertJsonPath('setup_id',$buy->id)
            ->assertJsonPath('chart_url','/?setup_id='.$buy->id)->assertHeader('Cache-Control','no-store, private');
        $this->assertStringContainsString('focus-tp1-card',$buyResponse->json('html'));
        $this->assertStringContainsString('TP1 price reached',$buyResponse->json('html'));
        $sellResponse=$this->getJson('/api/setups/'.$sell->id.'/focus')->assertOk()->assertJsonPath('setup_id',$sell->id);
        $this->assertStringContainsString('TP1 waiting for price',$sellResponse->json('html'));
        $this->assertStringNotContainsString('TP1 price reached',$sellResponse->json('html'));
        $this->assertNotEquals($buyResponse->json('version'),$sellResponse->json('version'));
    }

    public function test_target_updates_change_the_focus_version_without_touching_the_other_setup(): void
    {
        $buy=$this->setupRow();$sell=$this->setupRow(['direction'=>'SELL','tp1'=>90,'tp2'=>80]);
        $before=$this->getJson('/api/setups/'.$buy->id.'/focus')->json('version');
        $buy->update(['tp1_hit_at'=>now()]);
        $after=$this->getJson('/api/setups/'.$buy->id.'/focus')->json('version');
        $this->assertNotSame($before,$after);$this->assertNull($sell->fresh()->tp1_hit_at);
    }

    public function test_selected_archived_setup_is_visible_even_when_list_filters_exclude_it(): void
    {
        $archived=$this->setupRow(['status'=>'invalidated']);$this->setupRow(['direction'=>'SELL']);
        $this->get('/setups?setup_id='.$archived->id.'&direction=SELL&status=active')
            ->assertOk()->assertViewHas('focusedSetup',fn($s)=>$s->id===$archived->id)
            ->assertViewHas('setups',fn($rows)=>$rows->total()===1)
            ->assertSee('id="setup-focus-filter" value="'.$archived->id.'"',false);
    }

    public function test_unknown_or_invalid_selection_does_not_fall_back_to_another_setup(): void
    {
        $this->setupRow();
        $this->get('/setups?setup_id=999999')->assertNotFound();
        $this->getJson('/api/setups/999999/focus')->assertNotFound();
        $this->getJson('/setups?setup_id=bad')->assertUnprocessable();
    }

    public function test_chart_link_pins_the_selected_setup_and_its_timeframe(): void
    {
        $buy=$this->setupRow(['timeframe'=>'1h']);$this->setupRow(['direction'=>'SELL']);
        $market=\Mockery::mock(MarketDataService::class);$market->shouldReceive('bars')->andReturn([]);
        $this->app->instance(MarketDataService::class,$market);Redis::shouldReceive('get')->andReturn(null);
        $this->get('/?setup_id='.$buy->id)->assertOk()
            ->assertViewHas('activeSetup',fn($s)=>$s->id===$buy->id)->assertViewHas('initialTimeframe','1h')
            ->assertSee('Selected setup #'.$buy->id)->assertSee('addFocusedSetupLevels');
        $other=$this->setupRow(['instrument'=>'SI']);
        $this->get('/?setup_id='.$other->id)->assertStatus(409);
        $this->getJson('/api/setups/'.$other->id.'/focus')->assertOk()->assertJsonPath('chart_url',null);
    }

    public function test_empty_history_has_an_explicit_no_setup_state(): void
    {
        $this->get('/setups')->assertOk()->assertViewHas('focusedSetup',null)->assertSee('No saved setups yet.');
    }

    public function test_simultaneous_buy_and_sell_targets_are_tracked_independently(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-21 10:00:00','UTC'));
        $buy=$this->setupRow(['stop_loss'=>70]);$sell=$this->setupRow(['direction'=>'SELL','stop_loss'=>130,'tp1'=>90,'tp2'=>80]);
        $created=now()->subHour();$buy->forceFill(['created_at'=>$created])->save();$sell->forceFill(['created_at'=>$created])->save();
        $bar=['time'=>now()->subMinutes(30)->timestamp,'closed'=>true,'open'=>100,'high'=>111,'low'=>85,'close'=>105,
            'source'=>'clusterdelta','price_source'=>'mt5_broker','received_at'=>now()->timestamp];
        $market=\Mockery::mock(MarketDataService::class);$market->shouldReceive('bars')->with('15m',500)->once()->andReturn([json_encode($bar)]);
        $service=new SetupTargetService($market);$this->assertSame(2,$service->run());
        $this->assertNotNull($buy->fresh()->tp1_hit_at);$this->assertNull($buy->fresh()->tp2_hit_at);
        $this->assertNotNull($sell->fresh()->tp1_hit_at);$this->assertNull($sell->fresh()->tp2_hit_at);
    }
}

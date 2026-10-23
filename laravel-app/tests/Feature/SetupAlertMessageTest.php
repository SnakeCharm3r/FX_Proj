<?php

namespace Tests\Feature;

use App\Models\Setup;
use App\Services\SetupAlertMessageService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SetupAlertMessageTest extends TestCase
{
    private function setupRow(string $direction = 'BUY'): Setup
    {
        return (new Setup(['direction' => $direction, 'timeframe' => '15m', 'entry' => 4115.49,
            'stop_loss' => 4110.74, 'tp1' => 4122.62, 'tp2' => 4129.74, 'score' => 65,
            'instrument'=>'GC','confluence_detail'=>[['key'=>'absorption',
                'raw_value'=>$direction==='BUY'?12.5:-12.5,'total_volume'=>2468]]]))
            ->forceFill(['id' => 42, 'created_at' => CarbonImmutable::parse('2026-10-21 06:30:39', 'UTC')]);
    }

    public function test_entry_is_a_short_opportunity_message_with_delta_confluence_and_risk_levels(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00', 'UTC'));
        $message = app(SetupAlertMessageService::class)->entry($this->setupRow());
        foreach (['GC · Buy opportunity', '🟢 ▲ Positive Delta: <b>+12.5%</b>',
            '📊 Volume: <b>2,468</b>', 'Confluence: <b>65%</b>',
            'SL: <code>4110.74</code>', 'TP1: <code>4122.62</code>', 'TP2: <code>4129.74</code>'] as $text) {
            $this->assertStringContainsString($text, $message);
        }
        foreach (['Setup #42','15M','4115.49','ENTRY SIGNAL','21 Oct 2026'] as $text) {
            $this->assertStringNotContainsString($text,$message);
        }
        $this->assertDoesNotMatchRegularExpression('/\{[a-z_0-9]+\}/', $message);
        $sell = app(SetupAlertMessageService::class)->entry($this->setupRow('SELL'));
        $this->assertStringContainsString('Sell opportunity', $sell);
        $this->assertStringContainsString('🔴 ▼ Negative Delta: <b>-12.5%</b>', $sell);
    }

    public function test_absorption_strategy_entry_names_its_rule_instead_of_presenting_diagnostic_points_as_confidence(): void
    {
        $setup=$this->setupRow()->forceFill(['strategy_version'=>'zone_absorption_v2','score'=>15]);
        $message=app(SetupAlertMessageService::class)->entry($setup);
        $this->assertStringContainsString('Confluence: <b>15%</b>',$message);
        $this->assertStringNotContainsString('H1 zone',$message);
    }

    public function test_h1_trend_range_entry_names_every_required_orderflow_source(): void
    {
        $setup=$this->setupRow()->forceFill(['strategy_version'=>'h1_trend_range_v3','score'=>100]);
        $message=app(SetupAlertMessageService::class)->entry($setup);
        $this->assertStringContainsString('Confluence: <b>100%</b>',$message);
        $this->assertStringNotContainsString('H1 trend/range',$message);
    }

    public function test_target_templates_show_only_the_target_direction_setup_entry_and_exit_level(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00', 'UTC'));
        $setup = $this->setupRow('SELL')->forceFill([
            'tp1_hit_at' => CarbonImmutable::parse('2026-10-21 07:30:00', 'UTC'),
            'tp2_hit_at' => CarbonImmutable::parse('2026-10-21 08:00:00', 'UTC'),
        ]);
        foreach (['tp1' => '4122.62', 'tp2' => '4129.74'] as $target => $exit) {
            $message = app(SetupAlertMessageService::class)->targetReached($setup, $target);
            $icon = $target === 'tp1' ? '🎯1️⃣' : '🎯2️⃣';
            $this->assertSame($icon.' <b>'.strtoupper($target)." reached</b>\n".
                "🔴⬇️ XAUUSD SELL · #42\n📍 Entry: <code>4115.49</code>\n🏁 Exit: <code>".$exit.'</code>', $message);
        }
    }

    public function test_an_unrecorded_target_cannot_generate_a_reached_message(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(SetupAlertMessageService::class)->targetReached($this->setupRow(), 'tp1');
    }

    public function test_stop_message_requires_a_recorded_touch_and_retains_original_direction(): void
    {
        $setup = $this->setupRow('SELL')->forceFill(['sl_hit_at'=>CarbonImmutable::parse('2026-10-21 09:00:00','UTC')]);
        $message = app(SetupAlertMessageService::class)->stopReached($setup);
        $this->assertStringContainsString('🛑 <b>SL PRICE REACHED', $message);
        $this->assertStringContainsString('🔴⬇️ XAUUSD SELL', $message);
        $this->assertStringContainsString('4110.74', $message);
        $this->assertStringContainsString('21 Oct 2026 09:00:00 UTC', $message);
        $this->expectException(\InvalidArgumentException::class);
        app(SetupAlertMessageService::class)->stopReached($this->setupRow());
    }

    public function test_exit_reason_is_escaped_and_preserves_original_direction(): void
    {
        $message = app(SetupAlertMessageService::class)->exitSignal($this->setupRow('SELL'), 4112.5,
            'Manual <exit> & review', CarbonImmutable::parse('2026-10-21 12:00:00+03:00'));
        $this->assertStringContainsString('🚪 <b>XAUUSD — EXIT SIGNAL</b>', $message);
        $this->assertStringContainsString('🔴⬇️ Original SELL', $message);
        $this->assertStringContainsString('4112.50', $message);
        $this->assertStringContainsString('Manual &lt;exit&gt; &amp; review', $message);
        $this->assertStringContainsString('21 Oct 2026 09:00:00 UTC', $message);
    }
}

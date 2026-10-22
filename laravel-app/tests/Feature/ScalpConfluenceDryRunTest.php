<?php

namespace Tests\Feature;

use App\Services\ScalpConfluenceDryRunService;
use Tests\TestCase;

class ScalpConfluenceDryRunTest extends TestCase
{
    public function test_h1_range_participation_delta_and_price_confirmation_create_a_scalp_setup(): void
    {
        $bars=[];
        for($i=0;$i<21;$i++)$bars[]=$this->bar($i,96,101,95,100,100,0);
        $bars[]=$this->bar(21,95,105,90,103,200,40);
        $bars[]=$this->bar(22,101,105,99,103,100,10);
        $bars[]=$this->bar(23,103,141,102,139,100,10);
        $range=[['type'=>'DEMAND','low'=>90,'high'=>96,'origin_time'=>0,
            'confirmed_time'=>0,'invalidated_time'=>null]];

        $report=app(ScalpConfluenceDryRunService::class)->analyse(
            $bars,[],$range,0,24*300,'2026-10-12','2026-10-12'
        );

        $this->assertSame(1,$report['summary']['setups']);
        $this->assertSame(1,$report['summary']['worked']);
        $this->assertSame('BUY',$report['setups'][0]['direction']);
        $this->assertSame(100,$report['setups'][0]['score']);
        $this->assertSame(20.0,$report['setups'][0]['delta_pct']);
        $this->assertSame('WORKED_TP2',$report['setups'][0]['status']);
        $this->assertSame('TP2 · 100% CLOSED',$report['setups'][0]['outcome_bucket']);
        $this->assertSame(1,$report['outcome_counts']['tp2']);
        $this->assertSame(0,$report['outcome_counts']['loss']);
        $this->assertSame(100.0,$report['capital']['starting_balance']);
        $this->assertGreaterThan(100,$report['capital']['ending_balance']);
        $this->assertArrayHasKey('account_balance_after',$report['setups'][0]);
        $this->assertCount(1,$report['diagnostics']['profitable']);
        $this->assertCount(0,$report['diagnostics']['worst']);
        $this->assertCount(2,$report['diagnostics']['risk_audits']);
        $this->assertSame(1,$report['breakdowns']['confluence'][0]['setups']);
    }

    public function test_partial_strength_conditions_create_an_expanded_sixty_percent_setup(): void
    {
        $bars=[];
        for($i=0;$i<21;$i++)$bars[]=$this->bar($i,99,101,98,100,100,0);
        $bars[]=$this->bar(21,99,105,95,101,100,8);
        $bars[]=$this->bar(22,101,103,100,102,100,6);
        $bars[]=$this->bar(23,102,120,101,118,100,10);
        $range=[['type'=>'DEMAND','low'=>94,'high'=>100,'origin_time'=>0,
            'confirmed_time'=>0,'invalidated_time'=>null]];

        $report=app(ScalpConfluenceDryRunService::class)->analyse(
            $bars,[],$range,0,24*300,'2026-10-12','2026-10-12'
        );

        $this->assertSame(1,$report['summary']['setups']);
        $this->assertSame(60,$report['setups'][0]['score']);
        $this->assertSame('EXPANDED',$report['setups'][0]['confidence_tier']);
        $this->assertSame(['h1_range'=>25,'participation'=>10,'directional_delta'=>15,'price_confirmation'=>10],
            $report['setups'][0]['components']['points']);
    }

    private function bar(int $index,float $open,float $high,float $low,float $close,int $volume,int $delta): array
    {
        return ['time'=>$index*300,'open'=>$open,'high'=>$high,'low'=>$low,'close'=>$close,
            'volume'=>$volume,'delta'=>$delta,'buy_vol'=>($volume+$delta)/2,
            'sell_vol'=>($volume-$delta)/2,'closed'=>true];
    }
}

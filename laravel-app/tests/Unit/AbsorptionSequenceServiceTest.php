<?php

namespace Tests\Unit;

use App\Services\AbsorptionSequenceService;
use Tests\TestCase;

class AbsorptionSequenceServiceTest extends TestCase
{
    private function bar(int $time, float $open, float $high, float $low, float $close, int $delta, int $volume = 500): array
    {
        $buy = intdiv($volume + $delta, 2);
        return ['time'=>$time,'open'=>$open,'high'=>$high,'low'=>$low,'close'=>$close,
            'delta'=>$delta,'buy_vol'=>$buy,'sell_vol'=>$volume-$buy,'closed'=>true];
    }

    private function baseline(int $start): array
    {
        $bars=[];
        for($i=0;$i<20;$i++) $bars[]=$this->bar($start+$i*300,100,101,99,100.2,$i%2?50:-50,500);
        return $bars;
    }

    public function test_case_study_sequence_confirms_bearish_absorption_only_after_break(): void
    {
        $time=strtotime('2026-10-22 04:25 UTC');
        $bars=$this->baseline($time);
        $bars[]=$this->bar($time+20*300,4090.29,4097.00,4089.95,4091.99,128,1416);
        $bars[]=$this->bar($time+21*300,4091.98,4099.87,4091.82,4097.18,110,888);
        $bars[]=$this->bar($time+22*300,4097.13,4101.92,4096.43,4101.00,4,1016);
        $bars[]=$this->bar($time+23*300,4101.12,4102.91,4096.85,4098.63,7,755);

        $before=app(AbsorptionSequenceService::class)->analyse($bars,'5m');
        $this->assertFalse($before['qualified']);
        $bars[]=$this->bar($time+24*300,4098.39,4098.44,4091.43,4091.69,-194,834);
        $result=app(AbsorptionSequenceService::class)->analyse($bars,'5m');

        $this->assertTrue($result['qualified']);
        $this->assertSame('SELL',$result['direction']);
        $this->assertSame('BEAR_ABSORPTION',$result['type']);
        $this->assertSame(128.0,$result['effort_delta']);
        $this->assertSame(7.0,$result['rejection_delta']);
        $this->assertSame(-194.0,$result['confirmation_delta']);
        $this->assertStringContainsString('failed to hold',$result['reason']);
    }

    public function test_small_body_or_delta_alone_cannot_be_called_absorption(): void
    {
        $time=strtotime('2026-10-22 04:25 UTC');
        $bars=$this->baseline($time);
        $bars[]=$this->bar($time+20*300,100,104,96,100.1,400,1800);
        $bars[]=$this->bar($time+21*300,100,101,99,100.1,-200,900);
        $result=app(AbsorptionSequenceService::class)->analyse($bars,'5m');
        $this->assertFalse($result['qualified']);
        $this->assertSame('NONE',$result['type']);
    }
}

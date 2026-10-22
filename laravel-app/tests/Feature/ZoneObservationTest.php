<?php

namespace Tests\Feature;

use App\Services\RejectionRangeDetector;
use App\Services\ZoneObservationService;
use Tests\TestCase;

class ZoneObservationTest extends TestCase
{
    private function swing(): array
    {
        return [
            ['time'=>0,'open'=>107,'high'=>110,'low'=>104,'close'=>108,'closed'=>true],
            ['time'=>3600,'open'=>105,'high'=>109,'low'=>102,'close'=>104,'closed'=>true],
            ['time'=>7200,'open'=>100,'high'=>104,'low'=>95,'close'=>103,'closed'=>true],
            ['time'=>10800,'open'=>103,'high'=>107,'low'=>99,'close'=>105,'closed'=>true],
            ['time'=>14400,'open'=>105,'high'=>111,'low'=>103,'close'=>110,'closed'=>true],
        ];
    }

    public function test_green_demand_and_red_supply_are_symmetric_and_need_later_closed_hours(): void
    {
        $detector=app(RejectionRangeDetector::class);$bars=$this->swing();
        $this->assertSame([],$detector->detect($bars,17999));
        $zones=$detector->detect($bars,18000);
        $this->assertCount(1,$zones);$this->assertSame('DEMAND',$zones[0]['type']);
        $this->assertEquals(95,$zones[0]['low']);$this->assertEquals(100,$zones[0]['high']);
        $this->assertSame(18000,$zones[0]['confirmed_time']);
        $inverse=array_map(fn($b)=>array_merge($b,['open'=>200-$b['open'],'close'=>200-$b['close'],
            'high'=>200-$b['low'],'low'=>200-$b['high']]),$bars);
        $supply=$detector->detect($inverse,18000);
        $this->assertCount(1,$supply);$this->assertSame('SUPPLY',$supply[0]['type']);
        $this->assertEquals(100,$supply[0]['low']);$this->assertEquals(105,$supply[0]['high']);
        $bars[4]['closed']=false;$this->assertSame([],$detector->detect($bars,18000));
        $bars=$this->swing();$bars[4]['time']+=3600;$this->assertSame([],$detector->detect($bars,21600));
    }

    private function bar(int $time,float $open,float $high,float $low,float $close,int $delta=20): array
    {
        return compact('time','open','high','low','close','delta')+['closed'=>true,'buy_vol'=>(100+$delta)/2,
            'sell_vol'=>(100-$delta)/2,'volume'=>100];
    }

    public function test_zone_rejection_break_retest_continuation_and_reclaim_are_chronological(): void
    {
        $zone=['id'=>1,'type'=>'DEMAND','low'=>99,'high'=>101,'atr'=>8,'confirmed_time'=>3600];
        $h1=[['time'=>3600,'closed'=>true,'open'=>103,'high'=>105,'low'=>96,'close'=>98]];
        $bars=[$this->bar(6300,106,107,104,105),$this->bar(6600,100,104,98,103),
            $this->bar(6900,103,104,96,98,-20),$this->bar(7200,98,100,96,97,-30),
            $this->bar(7500,98,103,98,102,40)];
        $service=app(ZoneObservationService::class);
        $before=$service->analyse([$zone],$h1,$bars,[],0,7199);
        $this->assertContains('REJECTION',array_column($before,'event_type'));
        $this->assertNotContains('BREAK',array_column($before,'event_type'));
        $events=$service->analyse([$zone],$h1,$bars,[],0,7800);
        foreach(['BREAK','BROKEN_RETEST','CONTINUATION','COUNTER_BREAKOUT'] as $type)$this->assertContains($type,array_column($events,'event_type'));
        $break=collect($events)->firstWhere('event_type','BREAK');$this->assertSame(7200,$break['decision_time']);
        $continuation=collect($events)->firstWhere('event_type','CONTINUATION');$this->assertSame('SELL',$continuation['direction']);
        $reclaim=collect($events)->firstWhere('event_type','COUNTER_BREAKOUT');$this->assertSame('BUY',$reclaim['direction']);
        $this->assertSame('unavailable',$reclaim['evidence']['footprint_status']);
        $this->assertEquals(40,$reclaim['evidence']['delta_pct']);
        $this->assertFalse($reclaim['evidence']['entry_approved']);
        // The same price behaviour at supply must invert every directional label.
        $inverse=fn($b)=>array_merge($b,['open'=>200-$b['open'],'close'=>200-$b['close'],
            'high'=>200-$b['low'],'low'=>200-$b['high'],'delta'=>-($b['delta']??0)]);
        $supply=$service->analyse([array_merge($zone,['type'=>'SUPPLY'])],array_map($inverse,$h1),array_map($inverse,$bars),[],0,7800);
        $this->assertSame('BUY',collect($supply)->firstWhere('event_type','CONTINUATION')['direction']);
        $this->assertSame('SELL',collect($supply)->firstWhere('event_type','COUNTER_BREAKOUT')['direction']);
    }
}

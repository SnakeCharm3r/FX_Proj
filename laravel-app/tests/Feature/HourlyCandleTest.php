<?php

namespace Tests\Feature;

use App\Services\HourlyCandleService;
use Tests\TestCase;

class HourlyCandleTest extends TestCase
{
    private function quarters(int $hour): array
    {
        return array_map(fn($i)=>['time'=>$hour+$i*900,'open'=>100+$i,'high'=>102+$i,'low'=>99+$i,'close'=>101+$i,
            'closed'=>true,'buy_vol'=>7,'sell_vol'=>3,'delta'=>4,'volume'=>10,'received_at'=>$hour+3700],range(0,3));
    }

    public function test_complete_hour_aggregation_matches_ohlc_volume_and_delta(): void
    {
        $hour=1792548000;$bars=app(HourlyCandleService::class)->merge([],$this->quarters($hour),$hour+4000);
        $this->assertCount(1,$bars);$bar=$bars[0];$this->assertSame($hour,$bar['time']);
        $this->assertSame(100,$bar['open']);$this->assertSame(105,$bar['high']);$this->assertSame(99,$bar['low']);$this->assertSame(104,$bar['close']);
        $this->assertSame(40,$bar['volume']);$this->assertSame(16,$bar['delta']);$this->assertTrue($bar['closed']);
        $this->assertSame('aggregated_15m',$bar['candle_source']);
    }

    public function test_missing_or_developing_quarter_is_not_promoted_to_completed_h1(): void
    {
        $hour=1792548000;$service=app(HourlyCandleService::class);$parts=$this->quarters($hour);
        $this->assertSame([],$service->merge([],array_slice($parts,0,3),$hour+4000));
        $parts[3]['closed']=false;$this->assertSame([],$service->merge([],$parts,$hour+4000));
    }

    public function test_frozen_native_developing_bar_is_dropped_and_current_hour_is_labelled_developing(): void
    {
        $hour=1792548000;$service=app(HourlyCandleService::class);$parts=$this->quarters($hour);
        $native=array_merge($parts[0],['closed'=>false]);
        $this->assertSame([],$service->merge([$native],[],$hour+4000));
        $parts[1]['closed']=false;$current=$service->merge([],array_slice($parts,0,2),$hour+1000);
        $this->assertCount(1,$current);$this->assertFalse($current[0]['closed']);$this->assertSame(102,$current[0]['close']);
    }
}

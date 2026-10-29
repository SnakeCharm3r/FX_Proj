<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class FootprintService
{
    public function key(string $instrument, string $tf): string
    {
        return "clusterdelta:footprint:{$instrument}:{$tf}:latest";
    }

    public function historyKey(string $instrument,string $tf): string
    {
        return "clusterdelta:footprint:{$instrument}:{$tf}:history";
    }

    /** Chronological, internally validated native footprint bars for strategy evaluation. */
    public function bars(string $tf,int $limit=300): array
    {
        $instrument=config('clusterdelta.instrument','GC');$key=$this->historyKey($instrument,$tf);
        try{$times=array_reverse(Redis::zrevrange($key.':times',0,$limit-1));}
        catch(\Throwable){return [];}
        if(!$times)return [];
        return array_values(array_filter(array_map(fn($raw)=>json_decode($raw?:'',true),
            Redis::hmget($key.':values',...$times)),fn($bar)=>is_array($bar)));
    }

    public function activeTimeframe(): string
    {
        $best='15m';$receipt=0;
        foreach(config('orderflow.timeframes',['1m','5m','15m','30m','1h','4h','1d']) as $tf){
            try{$snapshot=$this->snapshot(config('clusterdelta.instrument','GC'),$tf);}catch(\Throwable){$snapshot=null;}
            if((int)($snapshot['received_at']??0)>$receipt){$best=$tf;$receipt=(int)$snapshot['received_at'];}
        }
        return $best;
    }

    public function analysisBar(array $bar,int $receivedAt=0,float $tick=.1): array
    {
        return $bar+['buy_vol'=>(float)($bar['ask']??0),'sell_vol'=>(float)($bar['bid']??0),
            'volume'=>(float)($bar['volume']??(($bar['ask']??0)+($bar['bid']??0))),
            'delta'=>(float)($bar['delta']??(($bar['ask']??0)-($bar['bid']??0))),
            'received_at'=>$bar['received_at']??$receivedAt,'source'=>'clusterdelta_footprint',
            'price_source'=>'GC_futures','tick_size'=>$bar['tick_size']??$tick];
    }

    public function enrich(array $bar, float $tick): array
    {
        usort($bar['levels'], fn ($a, $b) => $a['price'] <=> $b['price']);
        $pocVolume = -1;
        $ask = $bid = 0;
        foreach ($bar['levels'] as $i => $level) {
            $ask += $level['ask'];
            $bid += $level['bid'];
            $volume = $level['ask'] + $level['bid'];
            if ($volume > $pocVolume) { $pocVolume = $volume; $bar['poc'] = $level['price']; }
        }
        $bar['levels'] = $this->imbalanceLevels($bar['levels'], $tick);
        $bar['volume'] = $ask + $bid;
        $bar['delta'] = $ask - $bid;
        $bar['ask'] = $ask;
        $bar['bid'] = $bid;
        $bar['source_totals_match'] = (!isset($bar['reported_ask']) || $ask == $bar['reported_ask'])
            && (!isset($bar['reported_bid']) || $bid == $bar['reported_bid']);
        return $bar;
    }

    private function imbalanceLevels(array $levels, float $tick): array
    {
        $byTick = [];
        foreach ($levels as $level) $byTick[(int) round($level['price'] / $tick)] = $level;
        $ratio = config('orderflow.imbalance_ratio', 3);
        foreach ($levels as &$level) {
            $index = (int) round($level['price'] / $tick);
            $belowBid = $byTick[$index - 1]['bid'] ?? 0;
            $aboveAsk = $byTick[$index + 1]['ask'] ?? 0;
            $level['ask_imbalance'] = $belowBid > 0 && $level['ask'] >= 10 && $level['ask'] >= $ratio * $belowBid;
            $level['bid_imbalance'] = $aboveAsk > 0 && $level['bid'] >= 10 && $level['bid'] >= $ratio * $aboveAsk;
        }
        unset($level);
        return $levels;
    }

    /** Observed adjacent price ranges in one candle; no claim they remain active. */
    public function imbalanceRuns(array $bar, float $tick): array
    {
        $levels = $this->imbalanceLevels($bar['levels'], $tick);
        usort($levels, fn ($a, $b) => $a['price'] <=> $b['price']);
        $runs = [];
        foreach (['BULLISH' => 'ask_imbalance', 'BEARISH' => 'bid_imbalance'] as $direction => $flag) {
            $run = null; $previousIndex = null;
            foreach ($levels as $level) {
                $index = (int) round($level['price'] / $tick);
                if (!$level[$flag] || $index !== $previousIndex + 1) {
                    if ($run) $runs[] = $run;
                    $run = null;
                }
                if ($level[$flag]) {
                    $run ??= ['type' => $direction, 'low' => $level['price'], 'high' => $level['price'], 'count' => 0];
                    $run['high'] = $level['price']; $run['count']++;
                }
                $previousIndex = $index;
            }
            if ($run) $runs[] = $run;
        }
        return array_map(fn ($run) => $run + ['stacked' => $run['count'] >= config('orderflow.stacked_imbalance_count', 3)], $runs);
    }

    public function imbalanceSnapshot(string $tf): array
    {
        $instrument = config('clusterdelta.instrument', 'GC');
        $snapshot = $this->snapshot($instrument, $tf);
        $response = ['instrument' => $instrument, 'timeframe' => $tf, 'price_source' => 'GC_futures',
            'candles' => [], 'zones' => [], 'received_at' => $snapshot['received_at'] ?? null,
            'ratio' => config('orderflow.imbalance_ratio', 3),
            'stacked_count' => config('orderflow.stacked_imbalance_count', 3)];
        if (!$snapshot) return $response + ['status' => 'waiting_for_footprint',
            'reason' => 'No footprint cells for this timeframe. Check FXClusterDeltaFootprint in MT5.'];
        $mismatch = false;
        foreach ($snapshot['bars'] as $bar) {
            $response['candles'][] = array_intersect_key($bar, array_flip(['time', 'open', 'high', 'low', 'close']));
            if (($bar['source_totals_match'] ?? false) !== true) { $mismatch = true; continue; }
            foreach ($this->imbalanceRuns($bar, (float) $snapshot['tick_size']) as $run) {
                $response['zones'][] = $run + ['time' => $bar['time'], 'tf' => $tf, 'closed' => $bar['closed']];
            }
        }
        usort($response['candles'], fn ($a, $b) => $a['time'] <=> $b['time']);
        usort($response['zones'], fn ($a, $b) => ($b['time'] <=> $a['time']) ?: ($b['count'] <=> $a['count']));
        $fresh = $snapshot['received_at'] <= now()->timestamp + 5 && now()->timestamp - $snapshot['received_at'] < 60;
        return $response + ['status' => !$fresh ? 'stale' : ($mismatch ? 'totals_mismatch' : 'receiving'),
            'reason' => !$fresh ? 'Footprint feed is stale; showing the last observed ranges.'
                : ($mismatch ? 'Candle totals disagree; ranges from those candles are omitted.'
                : ($response['zones'] ? 'Showing observed GC price-level imbalances from exported footprint candles.'
                : 'Footprint cells received; no price levels meet the imbalance rule.'))];
    }

    public function snapshot(string $instrument, string $tf): ?array
    {
        $raw = Redis::get($this->key($instrument, $tf));
        return $raw ? json_decode($raw, true) : null;
    }

    public function correlate(array $snapshot): array
    {
        $times = array_column($snapshot['bars'], 'time');
        $key = "clusterdelta:{$snapshot['instrument']}:{$snapshot['tf']}:values";
        $values = $times ? Redis::hmget($key, ...array_map('strval', $times)) : [];
        foreach ($snapshot['bars'] as $i => &$bar) {
            $reference = isset($values[$i]) && $values[$i] ? json_decode($values[$i], true) : null;
            $bar['comparison'] = $reference ? [
                'bar_volume' => ($reference['buy_vol'] ?? 0) + ($reference['sell_vol'] ?? 0),
                'bar_delta' => $reference['delta'],
                'volume_difference' => $bar['volume'] - (($reference['buy_vol'] ?? 0) + ($reference['sell_vol'] ?? 0)),
                'delta_difference' => $bar['delta'] - $reference['delta'],
                'broker_close' => $reference['close'],
                'price_basis' => round($bar['close'] - $reference['close'], 8),
                'reference_received_at' => $reference['received_at'] ?? null,
            ] : null;
        }
        unset($bar);
        return $snapshot;
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\FootprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FootprintController extends ClusterDeltaController
{
    private function footprintIdentity(): array
    {
        return ['instrument' => 'required|in:GC',
            'tf' => ['required', Rule::in(['1m', '5m', '15m', '30m', '1h', '4h', '1d'])]];
    }

    public function storeFootprint(Request $request, FootprintService $footprint)
    {
        $this->authorizeBridge($request);
        $data = $request->validate($this->footprintIdentity() + [
            'symbol' => 'required|string|max:50',
            'contract' => 'sometimes|nullable|string|max:100', // Legacy exporter field; unverified vendor Exp value.
            'vendor_expiration' => 'sometimes|nullable|string|max:100',
            'tick_size' => 'required|numeric|min:0.00000001|max:10000',
            'bars' => 'required|array|min:1|max:40',
            'bars.*.time' => 'required|integer|min:1|distinct',
            'bars.*.closed' => 'required|boolean',
            'bars.*.open' => 'required|numeric|gt:0|max:100000000',
            'bars.*.high' => 'required|numeric|gt:0|max:100000000',
            'bars.*.low' => 'required|numeric|gt:0|max:100000000',
            'bars.*.close' => 'required|numeric|gt:0|max:100000000',
            'bars.*.ask' => 'required|integer|min:0|max:1000000000000',
            'bars.*.bid' => 'required|integer|min:0|max:1000000000000',
            'bars.*.reported_ask' => 'sometimes|integer|min:0|max:1000000000000',
            'bars.*.reported_bid' => 'sometimes|integer|min:0|max:1000000000000',
            'bars.*.delta_min' => 'required|integer|between:-1000000000000,1000000000000',
            'bars.*.delta_max' => 'required|integer|between:-1000000000000,1000000000000',
            'bars.*.levels' => 'required|array|min:1|max:5000',
            'bars.*.levels.*.price' => 'required|numeric|gt:0|max:100000000',
            'bars.*.levels.*.ask' => 'required|integer|min:0|max:1000000000000',
            'bars.*.levels.*.bid' => 'required|integer|min:0|max:1000000000000',
        ]);
        foreach ($data['bars'] as $i => $bar) {
            $fail = function ($message) use ($i) { throw ValidationException::withMessages(["bars.$i.levels" => $message]); };
            if ($bar['high'] < max($bar['open'], $bar['close'], $bar['low']) || $bar['low'] > min($bar['open'], $bar['close'])) $fail('Invalid footprint OHLC.');
            $indices = [];
            foreach ($bar['levels'] as $level) {
                $index = $level['price'] / $data['tick_size'];
                if (abs($index - round($index)) > 0.00001) $fail('Price is off the native tick grid.');
                if (isset($indices[(int) round($index)])) $fail('Duplicate price level.');
                $indices[(int) round($index)] = true;
                if ($level['price'] < $bar['low'] - $data['tick_size'] / 2 || $level['price'] > $bar['high'] + $data['tick_size'] / 2) $fail('Price level outside the candle range.');
            }
            if (array_sum(array_column($bar['levels'], 'ask')) != $bar['ask'] || array_sum(array_column($bar['levels'], 'bid')) != $bar['bid']) $fail('Price-level volumes do not match the supplied totals.');
            $data['bars'][$i] = $footprint->enrich($bar, (float) $data['tick_size']);
        }
        usort($data['bars'], fn ($a, $b) => $a['time'] <=> $b['time']);
        $offset = (int) config('clusterdelta.footprint_utc_offset_minutes', 0);
        $periods = ['1m' => 60, '5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '4h' => 14400, '1d' => 86400];
        foreach ($data['bars'] as &$bar) {
            $bar['source_time'] = $bar['time'];
            $bar['source_closed'] = $bar['closed'];
            $bar['time'] -= $offset * 60;
            if ($offset !== 0) $bar['closed'] = $bar['time'] + $periods[$data['tf']] <= now()->timestamp;
        }
        unset($bar);
        $data['source_utc_offset_minutes'] = $offset;
        // The source only calls this field Expiration; it does not identify a
        // futures contract. Keep it as raw metadata rather than asserting a contract.
        $data['vendor_expiration'] = $data['vendor_expiration'] ?? $data['contract'] ?? null;
        $data['contract'] = null;
        $data['source'] = 'clusterdelta_footprint';
        $data['price_source'] = 'GC_futures';
        $data['received_at'] = now()->timestamp;
        Redis::setex($footprint->key($data['instrument'], $data['tf']), 604800, json_encode($data));
        $historyKey=$footprint->historyKey($data['instrument'],$data['tf']);
        Redis::transaction(function($redis)use($data,$historyKey,$footprint){
            foreach($data['bars'] as $bar){
                $bar=$footprint->analysisBar($bar,$data['received_at'],(float)$data['tick_size']);
                $redis->hset($historyKey.':values',(string)$bar['time'],json_encode($bar));
                $redis->zadd($historyKey.':times',$bar['time'],(string)$bar['time']);
            }
            $redis->expire($historyKey.':values',604800);$redis->expire($historyKey.':times',604800);
        });
        app(\App\Services\ResearchArchiveService::class)->record('footprint', $data);
        return response()->json(['ok' => true, 'accepted' => count($data['bars'])]);
    }

    public function showFootprint(Request $request, FootprintService $footprint)
    {
        $data = $request->validate($this->footprintIdentity());
        $snapshot = $footprint->snapshot($data['instrument'], $data['tf']);
        return response()->json($snapshot ? $footprint->correlate($snapshot) : [
            'instrument' => $data['instrument'], 'tf' => $data['tf'], 'received_at' => null, 'bars' => [],
        ])->header('Cache-Control', 'no-store');
    }
}

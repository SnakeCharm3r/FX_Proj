<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;

class ClusterDeltaController extends Controller
{
    protected function authorizeBridge(Request $request): void
    {
        $token = (string) config('clusterdelta.bridge_token');
        abort_if($token === '', 503, 'ClusterDelta bridge is not configured.');
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);
    }

    public function instruments(Request $request)
    {
        $this->authorizeBridge($request);
        return response()->json(['source' => 'clusterdelta', 'availability_verified' => false,
            'instruments' => config('clusterdelta.instruments')]);
    }

    private function identityRules(): array
    {
        return [
            'instrument' => ['required', Rule::in(array_keys(config('clusterdelta.instruments')))],
            'tf' => ['required', Rule::in(['1m', '5m', '15m', '30m', '1h', '4h', '1d'])],
        ];
    }

    public function store(Request $request)
    {
        $this->authorizeBridge($request);
        $data = $request->validate($this->identityRules() + [
            'bars' => 'required|array|min:1|max:500',
            'bars.*.time' => 'required|integer|min:1',
            'bars.*.closed' => 'sometimes|boolean',
            'bars.*.volume' => 'required|numeric|min:0|max:1000000000000',
            'bars.*.open' => 'required|numeric|gt:0',
            'bars.*.high' => 'required|numeric|gt:0',
            'bars.*.low' => 'required|numeric|gt:0',
            'bars.*.close' => 'required|numeric|gt:0',
            'bars.*.delta' => 'required|numeric|between:-1000000000000,1000000000000',
        ]);
        foreach ($data['bars'] as $i => $bar) {
            if ($bar['high'] < max($bar['open'], $bar['close'], $bar['low']) || $bar['low'] > min($bar['open'], $bar['close'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(["bars.$i.high" => 'Invalid OHLC range.']);
            }
            if (abs($bar['delta']) > $bar['volume']) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "bars.$i.delta" => 'Absolute delta cannot exceed volume.',
                ]);
            }
        }
        $key = "clusterdelta:{$data['instrument']}:{$data['tf']}";
        // Each timestamp is a sorted-set member; repeated updates replace its hash value.
        Redis::transaction(function ($redis) use ($data, $key) {
            foreach ($data['bars'] as $bar) {
                $bar = [
                    'time' => (int) $bar['time'], 'volume' => (float) $bar['volume'],
                    'closed' => (bool) ($bar['closed'] ?? true),
                    'delta' => (float) $bar['delta'],
                    'buy_vol' => ($bar['volume'] + $bar['delta']) / 2,
                    'sell_vol' => ($bar['volume'] - $bar['delta']) / 2,
                    'open' => (float) $bar['open'], 'high' => (float) $bar['high'],
                    'low' => (float) $bar['low'], 'close' => (float) $bar['close'],
                    'price_source' => 'mt5_broker',
                    'source' => 'clusterdelta', 'received_at' => now()->timestamp,
                ];
                $redis->hset($key.':values', (string) $bar['time'], json_encode($bar));
                $redis->zadd($key.':times', $bar['time'], (string) $bar['time']);
            }
            $redis->expire($key.':values', 604800);
            $redis->expire($key.':times', 604800);
        });
        app(\App\Services\ResearchArchiveService::class)->record('broker', $data);
        return response()->json(['ok' => true, 'accepted' => count($data['bars'])]);
    }

    public function bars(Request $request)
    {
        $this->authorizeBridge($request);
        $data = $request->validate($this->identityRules());
        $key = "clusterdelta:{$data['instrument']}:{$data['tf']}";
        $times = array_reverse(Redis::zrevrange($key.':times', 0, 499));
        $bars = $times ? array_map(fn ($v) => json_decode($v, true), Redis::hmget($key.':values', ...$times)) : [];
        return response()->json(['source' => 'clusterdelta', 'instrument' => $data['instrument'],
            'tf' => $data['tf'], 'bars' => $bars]);
    }
}

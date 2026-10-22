<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BrokerHistoryController extends ClusterDeltaController
{
    public function storeHistory(Request $request)
    {
        $this->authorizeBridge($request);
        $data = $request->validate([
            'instrument' => ['required', Rule::in(array_keys(config('clusterdelta.instruments')))],
            'symbol' => 'required|string|max:40', 'tf' => 'required|in:1h',
            'bars' => 'required|array|min:1|max:500',
            'bars.*.time' => 'required|integer|min:1',
            'bars.*.open' => 'required|numeric|gt:0', 'bars.*.high' => 'required|numeric|gt:0',
            'bars.*.low' => 'required|numeric|gt:0', 'bars.*.close' => 'required|numeric|gt:0',
        ]);
        $rows = [];
        foreach ($data['bars'] as $i => $bar) {
            if ($bar['time'] % 3600 !== 0 || $bar['time'] + 3600 > now()->timestamp
                || $bar['high'] < max($bar['open'], $bar['close'], $bar['low'])
                || $bar['low'] > min($bar['open'], $bar['close'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(["bars.$i" => 'Expected valid, completed UTC H1 broker candle.']);
            }
            $rows[$bar['time']] = array_intersect_key($bar, array_flip(['time','open','high','low','close']))
                + ['instrument' => $data['instrument'], 'symbol' => $data['symbol']];
        }
        DB::transaction(function () use ($rows, $data) {
            DB::table('broker_hourly_prices')->upsert(array_values($rows), ['instrument','time'], ['symbol','open','high','low','close']);
            // This endpoint accepts a full consecutive CopyRates batch, including session gaps.
            DB::table('broker_history_windows')->upsert([['instrument'=>$data['instrument'],
                'first_time'=>min(array_keys($rows)), 'last_time'=>max(array_keys($rows))]],
                ['instrument','first_time','last_time'], ['last_time']);
        });
        return response()->json(['ok' => true, 'accepted' => count($rows), 'kind' => 'broker_price_history']);
    }
}

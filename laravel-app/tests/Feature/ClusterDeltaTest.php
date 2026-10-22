<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ClusterDeltaTest extends TestCase
{
    public function test_developing_candle_updates_keep_the_same_timestamp_identity(): void
    {
        config(['clusterdelta.bridge_token' => 'test-secret']);
        $redis = \Mockery::mock();
        $redis->shouldReceive('hset')->twice()->with('clusterdelta:GC:15m:values', '1700000000',
            \Mockery::on(fn ($json) => json_decode($json, true)['closed'] === false));
        $redis->shouldReceive('zadd')->twice()->with('clusterdelta:GC:15m:times', 1700000000, '1700000000');
        $redis->shouldReceive('expire')->times(4);
        Redis::shouldReceive('transaction')->twice()->andReturnUsing(fn ($callback) => $callback($redis));
        $bar = ['time' => 1700000000, 'closed' => false, 'open' => 2000,
            'high' => 2002, 'low' => 1999, 'close' => 2001, 'volume' => 10, 'delta' => 4];
        foreach ([2001, 2002] as $close) {
            $bar['close'] = $close;
            $this->withToken('test-secret')->postJson('/api/clusterdelta/bars', [
                'instrument' => 'GC', 'tf' => '15m', 'bars' => [$bar],
            ])->assertOk();
        }
    }

    public function test_bridge_fails_closed_and_requires_its_own_token(): void
    {
        config(['clusterdelta.bridge_token' => '']);
        $this->getJson('/api/clusterdelta/instruments')->assertStatus(503);
        config(['clusterdelta.bridge_token' => 'test-secret']);
        $this->getJson('/api/clusterdelta/instruments')->assertUnauthorized();
        $this->withToken('test-secret')->getJson('/api/clusterdelta/instruments')
            ->assertOk()->assertJsonPath('instruments.GC.id', 24)
            ->assertJsonPath('availability_verified', false);
    }

    public function test_invalid_market_data_never_reaches_redis(): void
    {
        config(['clusterdelta.bridge_token' => 'test-secret']);
        Redis::shouldReceive('transaction')->never();
        $payload = ['instrument' => 'GC', 'tf' => '15m',
            'bars' => [['time' => 1700000000, 'open' => 2000, 'high' => 2002, 'low' => 1999, 'close' => 2001, 'volume' => 10, 'delta' => 11]]];
        $this->withToken('test-secret')->postJson('/api/clusterdelta/bars', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('bars.0.delta');
        $payload['instrument'] = 'UNKNOWN';
        $this->withToken('test-secret')->postJson('/api/clusterdelta/bars', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('instrument');
    }

    public function test_bar_updates_use_timestamp_identity_and_true_volume(): void
    {
        config(['clusterdelta.bridge_token' => 'test-secret']);
        $redis = \Mockery::mock();
        $redis->shouldReceive('hset')->once()->with('clusterdelta:GC:15m:values', '1700000000',
            \Mockery::on(fn ($json) => json_decode($json, true)['buy_vol'] === 7 && json_decode($json, true)['sell_vol'] === 3));
        $redis->shouldReceive('zadd')->once()->with('clusterdelta:GC:15m:times', 1700000000, '1700000000');
        $redis->shouldReceive('expire')->twice();
        Redis::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback($redis));
        $this->withToken('test-secret')->postJson('/api/clusterdelta/bars', [
            'instrument' => 'GC', 'tf' => '15m',
            'bars' => [['time' => 1700000000, 'open' => 2000, 'high' => 2002, 'low' => 1999, 'close' => 2001, 'volume' => 10, 'delta' => 4]],
        ])->assertOk()->assertJsonPath('accepted', 1);
    }
}

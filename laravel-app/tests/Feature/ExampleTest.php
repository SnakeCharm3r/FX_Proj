<?php

namespace Tests\Feature;

use App\Services\MarketDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $market = \Mockery::mock(MarketDataService::class);
        $market->shouldReceive('activeTimeframe')->once()->andReturn('15m');
        $market->shouldReceive('bars')->andReturn([]);
        $this->app->instance(MarketDataService::class, $market);

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}

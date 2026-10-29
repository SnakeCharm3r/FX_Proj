<?php

namespace Tests\Feature;

use App\Services\MarketDataService;
use App\Services\FootprintService;
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
        $footprint = \Mockery::mock(FootprintService::class);
        $footprint->shouldReceive('activeTimeframe')->once()->andReturn('15m');
        $footprint->shouldReceive('bars')->andReturn([]);
        $this->app->instance(FootprintService::class, $footprint);

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}

<?php

namespace Tests\Feature;

use App\Services\ResearchArchiveService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ResearchArchiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['research.archive_enabled'=>true]);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-22 10:00:10','UTC'));
    }

    private function snapshot(): array
    {
        return ['instrument'=>'GC','tf'=>'5m','symbol'=>'XAUUSDm','tick_size'=>.1,
            'received_at'=>now()->timestamp,'authorization'=>'must not be archived',
            'source_utc_offset_minutes'=>180,'bars'=>[
                ['time'=>strtotime('2026-10-22 09:55 UTC'),'closed'=>true,'open'=>100,'high'=>101,'low'=>99,'close'=>100,
                 'delta'=>-10,'volume'=>30,'levels'=>[['price'=>100,'ask'=>10,'bid'=>20]]],
                ['time'=>strtotime('2026-10-22 10:00 UTC'),'closed'=>false,'open'=>100,'high'=>101,'low'=>99,'close'=>100]]];
    }

    private function frames(): array
    {
        return array_values(array_filter(Storage::disk('local')->allFiles('research'),fn($p)=>str_ends_with($p,'.json.gz')));
    }

    public function test_only_closed_frames_are_archived_with_first_observation_and_clock_metadata(): void
    {
        app(ResearchArchiveService::class)->record('footprint',$this->snapshot());
        $paths=$this->frames();$this->assertCount(1,$paths);
        $raw=gzdecode(Storage::disk('local')->get($paths[0]));$frame=json_decode($raw,true);
        $this->assertSame(now()->timestamp,$frame['first_observed_at']);
        $this->assertFalse($frame['late_observation']);
        $this->assertSame(180,$frame['metadata']['source_utc_offset_minutes']);
        $this->assertCount(1,$frame['bar']['levels']);
        $this->assertStringNotContainsString('must not be archived',$raw);
    }

    public function test_identical_frames_are_deduplicated_and_revisions_preserve_first_observation(): void
    {
        $service=app(ResearchArchiveService::class);$snapshot=$this->snapshot();$service->record('footprint',$snapshot);
        $first=Storage::disk('local')->get($this->frames()[0]);
        $this->travel(120)->seconds();$snapshot['received_at']=now()->timestamp;
        $service->record('footprint',$snapshot);$this->assertCount(1,$this->frames());
        $this->assertSame($first,Storage::disk('local')->get($this->frames()[0]));
        $snapshot['bars'][0]['levels'][0]['bid']=21;
        $service->record('footprint',$snapshot);$this->assertCount(2,$this->frames());
    }

    public function test_backfills_are_labelled_late_and_future_frames_are_not_archived(): void
    {
        $snapshot=$this->snapshot();$snapshot['bars'][0]['time']-=3600;
        $snapshot['bars'][1]['closed']=true; // incorrectly marked closed, but time still developing
        app(ResearchArchiveService::class)->record('broker',$snapshot);
        $this->assertCount(1,$this->frames());
        $frame=json_decode(gzdecode(Storage::disk('local')->get($this->frames()[0])),true);
        $this->assertTrue($frame['late_observation']);
        config(['research.archive_enabled'=>false]);
        app(ResearchArchiveService::class)->record('footprint',$snapshot);
        $this->assertCount(1,$this->frames());
    }
}

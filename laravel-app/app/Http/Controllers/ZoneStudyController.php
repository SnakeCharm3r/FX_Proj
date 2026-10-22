<?php

namespace App\Http\Controllers;

use App\Models\ZoneObservation;
use App\Services\HourlyPriceHistoryService;
use App\Services\PriceZoneService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ZoneStudyController extends Controller
{
    public function data(Request $request,PriceZoneService $zones,HourlyPriceHistoryService $history)
    {
        $input=$request->validate(['date'=>'sometimes|date_format:Y-m-d','format'=>'sometimes|in:json,csv']);
        $date=$input['date']??CarbonImmutable::now('Africa/Dar_es_Salaam')->format('Y-m-d');
        $start=CarbonImmutable::parse($date.' 00:00:00','Africa/Dar_es_Salaam')->timestamp;
        $end=min(now()->timestamp,$start+86400);
        $scope=$zones->scope();
        $events=ZoneObservation::whereIn('price_zone_id',(clone $scope)->select('id'))
            ->whereBetween('decision_time',[$start,$end])->orderBy('decision_time')->orderBy('id')->get();
        $levels=(clone $scope)->where('confirmed_time','<=',$end)->orderBy('origin_time')->get();
        $candles=array_values(array_filter($history->bars(500),fn($b)=>$b['time']<=$end));
        usort($candles,fn($a,$b)=>$a['time']<=>$b['time']);
        $candles=array_slice($candles,-100);
        $candles=array_map(fn($b)=>array_intersect_key($b,array_flip(['time','open','high','low','close'])),$candles);
        if(($input['format']??'json')==='csv'){
            return response()->streamDownload(function()use($events){
                $out=fopen('php://output','w');
                fputcsv($out,['Time EAT','Zone ID','Zone type','Zone low','Zone high','Event','Direction','XAUUSD close',
                    'GC volume','GC delta','Delta %','CVD 20 M5 bars','Absorption','Footprint','Buy stack','Sell stack','POC rejection','Retrospective']);
                foreach($events as $event){$e=$event->evidence;
                    fputcsv($out,[CarbonImmutable::createFromTimestampUTC($event->decision_time)->setTimezone('Africa/Dar_es_Salaam')->format('Y-m-d H:i:s'),
                        $event->price_zone_id,$e['zone_type'],$e['zone_low'],$e['zone_high'],$event->event_type,$event->direction,
                        $event->price,$e['volume'],$e['delta'],$e['delta_pct'],$e['cvd'],$e['absorption']['type'],
                        $e['footprint_status'],$e['footprint']['footprint_bull_stack']??null,$e['footprint']['footprint_bear_stack']??null,
                        $e['footprint']['poc_rejection']??null,$event->historical?'yes':'no']);
                }fclose($out);
            },'xauusd-zones-'.$date.'.csv',['Content-Type'=>'text/csv']);
        }
        return response()->json(['date'=>$date,'timezone'=>'Africa/Dar_es_Salaam','start'=>$start,'end'=>$end,
            'version'=>config('price_zones.version'),'candles'=>$candles,'zones'=>$levels,'events'=>$events,
            'summary'=>$events->countBy('event_type'),'silent'=>true,
            'footprint_matched_events'=>$events->filter(fn($event)=>$event->evidence['footprint_status']==='matched')->count(),
            'monitor'=>array_diff_key($zones->status(),['zones'=>true])])->header('Cache-Control','no-store');
    }
}

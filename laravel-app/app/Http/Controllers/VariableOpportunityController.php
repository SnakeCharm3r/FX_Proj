<?php

namespace App\Http\Controllers;

use App\Services\VariableOpportunityService;
use App\Services\StrategyDryRunService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class VariableOpportunityController extends Controller
{
    public function data(Request $request,VariableOpportunityService $opportunities,StrategyDryRunService $replay)
    {
        $input=$request->validate(['date'=>'sometimes|date_format:Y-m-d','price_source'=>'sometimes|in:mt5_broker,GC_futures']);
        $priceSource=$input['price_source']??'mt5_broker';
        $date=$input['date']??now('Africa/Dar_es_Salaam')->format('Y-m-d');
        $start=CarbonImmutable::parse($date.' 00:00:00','UTC')->timestamp;$end=$start+86400;
        $rows=$opportunities->scope()->whereBetween('candle_time',[$start,$end-1])->orderBy('candle_time')->get();
        $bars=[];
        foreach($replay->archive('footprint',$date) as $bar)$bars[$bar['time']]=$bar;
        try{
            foreach(app(\App\Services\FootprintService::class)->bars('5m',500) as $bar){
                if($bar['time']>=$start&&$bar['time']<$end&&$bar['time']<=now()->timestamp)$bars[$bar['time']]=$bar;
            }
        }catch(\Throwable){ /* Historical chart stays readable when the live store is unavailable. */ }
        $footprintBars=$bars;
        if($priceSource==='mt5_broker'){
            $bars=[];
            foreach($replay->archive('broker',$date) as $bar)$bars[$bar['time']]=$bar;
            try{
                foreach(app(\App\Services\MarketDataService::class)->storedBars('5m',500) as $raw){
                    $bar=json_decode($raw,true);
                    if(($bar['price_source']??'')==='mt5_broker'&&$bar['time']>=$start&&$bar['time']<$end&&$bar['time']<=now()->timestamp)
                        $bars[$bar['time']]=$bar;
                }
            }catch(\Throwable){}
        }
        ksort($bars);ksort($footprintBars);
        $candles=array_values(array_map(fn($b)=>array_intersect_key($b,array_flip(['time','open','high','low','close'])),$bars));
        $latest=$bars?end($bars):null;
        $lastReceipt=$latest['received_at']??null;
        $fpLatest=$footprintBars?end($footprintBars):null;
        $fpReceipt=$fpLatest['received_at']??null;
        return response()->json(['date'=>$date,'timeframe'=>'5m','timezone'=>'Africa/Dar_es_Salaam',
            'price_source'=>$priceSource,'orderflow_source'=>'GC_futures',
            'period_start'=>$start,'period_end'=>$end,'strategy'=>VariableOpportunityService::VERSION,
            'enabled'=>config('orderflow.variable_opportunities_enabled'),
            'telegram_enabled'=>config('orderflow.external_alerts_enabled'),
            'feed_fresh'=>$fpReceipt!==null&&$fpReceipt<=now()->timestamp+5&&now()->timestamp-$fpReceipt<60,
            'chart_fresh'=>$lastReceipt!==null&&$lastReceipt<=now()->timestamp+5&&now()->timestamp-$lastReceipt<60,
            'chart_received_at'=>$lastReceipt,
            'candles'=>$candles,'opportunities'=>$rows,
            'summary'=>['total'=>$rows->count(),'buy'=>$rows->where('direction','BUY')->count(),
                'sell'=>$rows->where('direction','SELL')->count(),'sent'=>$rows->where('telegram_status','sent')->count()],
            'rule_description'=>['25'=>'Increased participation with directional delta',
                '50'=>'Increased participation at a matching H1 zone',
                '75'=>'Completed directional absorption without a matching H1 zone',
                '100'=>'Completed directional absorption at a matching H1 zone']
        ])->header('Cache-Control','no-store');
    }
}

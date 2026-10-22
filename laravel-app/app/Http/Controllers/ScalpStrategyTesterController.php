<?php

namespace App\Http\Controllers;

use App\Services\ScalpConfluenceDryRunService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ScalpStrategyTesterController extends Controller
{
    public function index(Request $request,ScalpConfluenceDryRunService $tester)
    {
        $validated=$request->validate([
            'from'=>'sometimes|date_format:Y-m-d',
            'to'=>'sometimes|date_format:Y-m-d|after_or_equal:from',
        ]);
        $lastWeek=CarbonImmutable::now('UTC')->startOfWeek()->subWeek();
        $from=$validated['from']??$lastWeek->format('Y-m-d');
        $to=$validated['to']??$lastWeek->endOfWeek()->format('Y-m-d');
        $report=$tester->run($from,$to);
        return view('setups.scalp-tester',compact('report','from','to'));
    }
}

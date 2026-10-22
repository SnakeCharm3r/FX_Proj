<?php

namespace App\Http\Controllers;

use App\Services\StrategyDryRunService;
use Illuminate\Http\Request;

class StrategyTesterController extends Controller
{
    public function index(Request $request,StrategyDryRunService $tester)
    {
        $validated=$request->validate(['date'=>'sometimes|date_format:Y-m-d']);
        $date=$validated['date']??'2026-10-22';
        $report=$tester->run($date);
        return view('setups.tester',compact('report','date'));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Signal;

class SignalController extends Controller
{
    public function index()
    {
        $signals        = Signal::orderByDesc('created_at')->paginate(50);
        $totalSignals   = Signal::count();
        $todaySignals   = Signal::whereDate('created_at', today())->count();
        $bullishSignals = Signal::whereIn('direction', ['BULLISH', 'BUY'])->count();
        $bearishSignals = Signal::whereIn('direction', ['BEARISH', 'SELL'])->count();

        return view('signals.index', compact(
            'signals', 'totalSignals', 'todaySignals', 'bullishSignals', 'bearishSignals'
        ));
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Setup;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function index(Request $request)
    {
        $query = Setup::query()->orderByDesc('created_at');

        if ($request->filled('direction')) $query->where('direction', $request->direction);
        if ($request->filled('timeframe')) $query->where('timeframe', $request->timeframe);
        if ($request->filled('status'))    $query->where('status',    $request->status);
        if ($request->filled('min_score')) $query->where('score', '>=', (int) $request->min_score);
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);

        $setups        = $query->paginate(25);
        $totalSetups   = Setup::count();
        $buyCount      = Setup::where('direction', 'BUY')->count();
        $sellCount     = Setup::where('direction', 'SELL')->count();
        $avgScore      = round(Setup::avg('score') ?? 0, 1);
        $highConfCount = Setup::where('score', '>=', 70)->count();
        $activeCount   = Setup::where('status', 'active')->count();

        return view('setups.index', compact(
            'setups', 'totalSetups', 'buyCount', 'sellCount',
            'avgScore', 'highConfCount', 'activeCount'
        ));
    }

    public function show(Setup $setup)
    {
        return response()->json($setup);
    }
}

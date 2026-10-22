<?php

namespace App\Http\Controllers;

use App\Models\Setup;
use App\Models\SetupObservation;
use Illuminate\Http\Request;

class SetupController extends Controller
{
    public function index(Request $request)
    {
        $selection = $request->validate(['setup_id' => 'sometimes|integer|min:1']);
        $focusedSetup = isset($selection['setup_id']) ? Setup::findOrFail($selection['setup_id'])
            : (Setup::whereIn('status', ['active', 'triggered'])->orderByDesc('id')->first() ?? Setup::orderByDesc('id')->first());
        $setupChoices = Setup::orderByRaw("CASE WHEN status IN ('active', 'triggered') THEN 0 ELSE 1 END")
            ->orderByDesc('id')->limit(100)->get(['id', 'direction', 'timeframe', 'status', 'created_at']);
        if ($focusedSetup && !$setupChoices->contains('id', $focusedSetup->id)) $setupChoices->push($focusedSetup);
        $focusVersion = $focusedSetup ? hash('sha256', $focusedSetup->toJson()) : null;
        $query = Setup::query()->orderByDesc('created_at');

        if ($request->filled('direction')) $query->where('direction', $request->direction);
        if ($request->filled('timeframe')) $query->where('timeframe', $request->timeframe);
        if ($request->filled('status'))    $query->where('status',    $request->status);
        if ($request->filled('min_score')) $query->where('score', '>=', (int) $request->min_score);
        if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);

        $setups        = $query->paginate(25)->withQueryString();
        $totalSetups   = Setup::count();
        $buyCount      = Setup::where('direction', 'BUY')->count();
        $sellCount     = Setup::where('direction', 'SELL')->count();
        $avgScore      = round(Setup::avg('score') ?? 0, 1);
        $highConfCount = Setup::where('score', '>=', 70)->count();
        $activeCount   = Setup::where('status', 'active')->count();
        $historyFloor=(int)config('orderflow.history_min_score',45);
        $historyFilters=$request->validate([
            'obs_direction'=>'nullable|in:BUY,SELL,NEUTRAL',
            'obs_timeframe'=>['nullable',\Illuminate\Validation\Rule::in(config('orderflow.timeframes'))],
            'obs_band'=>'nullable|in:below65,below_live,live',
            'obs_from'=>'nullable|date_format:Y-m-d',
        ]);
        $observed=SetupObservation::where('source',config('orderflow.connector'))
            ->where('instrument',config('clusterdelta.instrument','GC'))->where('score','>=',$historyFloor);
        $observationCounts=['total'=>(clone $observed)->count(),
            'below65'=>(clone $observed)->where('score','<',65)->count(),
            'live'=>(clone $observed)->whereNotNull('setup_id')->count()];
        if (!empty($historyFilters['obs_direction'])) $observed->where('direction',$historyFilters['obs_direction']);
        if (!empty($historyFilters['obs_timeframe'])) $observed->where('timeframe',$historyFilters['obs_timeframe']);
        if (!empty($historyFilters['obs_from'])) {
            $from=\Carbon\CarbonImmutable::parse($historyFilters['obs_from'],'Africa/Dar_es_Salaam')->utc();
            $observed->where('spotted_at','>=',$from);
        }
        $band=$historyFilters['obs_band']??null;
        if ($band==='below65') $observed->where('score','<',65);
        if ($band==='below_live') $observed->whereColumn('score','<','signal_threshold');
        if ($band==='live') $observed->whereNotNull('setup_id');
        $observations=$observed->with('setup')->orderByDesc('spotted_at')->orderByDesc('id')
            ->paginate(20,['*'],'observations_page')->withQueryString();

        return view('setups.index', compact(
            'setups', 'totalSetups', 'buyCount', 'sellCount',
            'avgScore', 'highConfCount', 'activeCount', 'focusedSetup', 'setupChoices', 'focusVersion',
            'observations','observationCounts','historyFloor'
        ));
    }

    public function observation(SetupObservation $observation)
    {
        abort_unless($observation->source===config('orderflow.connector')
            && $observation->instrument===config('clusterdelta.instrument','GC')
            && $observation->score >= config('orderflow.history_min_score',45),404);
        $observation->load('setup');
        return view('setups.observation-detail',compact('observation'));
    }

    public function show(Setup $setup)
    {
        return response()->json($setup)->header('Cache-Control', 'no-store');
    }

    public function focus(Setup $setup)
    {
        return response()->json(['setup_id' => $setup->id,
            'version' => hash('sha256', $setup->toJson()),
            'chart_url' => $setup->source === config('orderflow.connector') && $setup->instrument === config('clusterdelta.instrument')
                ? route('dashboard', ['setup_id' => $setup->id], false) : null,
            'html' => view('setups.focus-detail', ['focusedSetup' => $setup])->render()])
            ->header('Cache-Control', 'no-store');
    }
}

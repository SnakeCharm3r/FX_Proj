<?php

namespace App\Http\Controllers;

use App\Models\AlertLog;
use App\Services\AlertDispatchService;

class AlertController extends Controller
{
    public function index()
    {
        $alertLogs = AlertLog::orderByDesc('created_at')->paginate(30);
        return view('alerts.index', compact('alertLogs'));
    }

    public function test()
    {
        try {
            app(AlertDispatchService::class)->sendTest();
            return response()->json(['message' => 'Test alert dispatched successfully']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed: ' . $e->getMessage()], 500);
        }
    }
}

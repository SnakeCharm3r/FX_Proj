<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $pythonRunning = $this->isPythonRunning();
        return view('settings.index', compact('pythonRunning'));
    }

    public function update(Request $request)
    {
        return response()->json(['message' => 'Settings saved successfully']);
    }

    private function isPythonRunning(): bool
    {
        try {
            $pid = base_path('../python-ingestion/ingestion.pid');
            if (!file_exists($pid)) return false;
            return posix_kill((int) trim(file_get_contents($pid)), 0);
        } catch (\Exception) { return false; }
    }
}

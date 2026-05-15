<?php

namespace App\Http\Controllers;

class OrderflowController extends Controller
{
    public function delta()    { return view('orderflow.delta'); }
    public function imbalance(){ return view('orderflow.imbalance'); }
    public function cvd()      { return view('orderflow.cvd'); }
}

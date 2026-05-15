<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('xauusd.ticks',   fn() => true);
Broadcast::channel('xauusd.metrics', fn() => true);
Broadcast::channel('xauusd.setups',  fn() => true);
Broadcast::channel('xauusd.candles', fn() => true);

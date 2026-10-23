<?php

namespace App\Services;

use App\Models\Setup;
use DateTimeInterface;

class SetupAlertMessageService
{
    public function entry(Setup $setup): string
    {
        return $this->render('new_setup', $setup, $setup->created_at);
    }

    public function targetReached(Setup $setup, string $target): string
    {
        if (!in_array($target, ['tp1', 'tp2'], true) || !$setup->{$target.'_hit_at'}) {
            throw new \InvalidArgumentException('A recorded TP1 or TP2 price touch is required.');
        }
        return $this->render($target.'_hit', $setup, $setup->{$target.'_hit_at'});
    }

    public function stopReached(Setup $setup): string
    {
        if (!$setup->sl_hit_at) throw new \InvalidArgumentException('A recorded SL price touch is required.');
        return $this->render('sl_hit', $setup, $setup->sl_hit_at);
    }

    // Prepared for an explicit exit event; no stop-loss or order-fill event is inferred.
    public function exitSignal(Setup $setup, float $price, string $reason, DateTimeInterface $time): string
    {
        if (!is_finite($price) || $price <= 0 || trim($reason) === '') {
            throw new \InvalidArgumentException('An exit price and reason are required.');
        }
        return $this->render('exit', $setup, $time, [
            '{exit_price}' => number_format($price, 2, '.', ''), '{reason}' => $reason,
        ]);
    }

    private function render(string $template, Setup $setup, DateTimeInterface $time, array $extra = []): string
    {
        $icon = match ($setup->direction) {
            'BUY' => '🟢⬆️', 'SELL' => '🔴⬇️',
            default => throw new \InvalidArgumentException('BUY or SELL direction required.'),
        };
        $absorption = collect($setup->confluence_detail ?? [])->firstWhere('key', 'absorption');
        $deltaPercentage = is_numeric($absorption['raw_value'] ?? null)
            ? sprintf('%+.1f%%', (float) $absorption['raw_value'])
            : 'unavailable';
        $positive = (float)($absorption['raw_value'] ?? 0) >= 0;
        $volume = collect($setup->confluence_detail ?? [])->first(
            fn($signal) => isset($signal['total_volume']) && is_numeric($signal['total_volume'])
        )['total_volume'] ?? null;
        $values = array_merge([
            '{direction_icon}' => $icon, '{direction}' => $setup->direction,
            '{instrument}' => $setup->instrument ?: config('clusterdelta.instrument','GC'),
            '{opportunity}' => ucfirst(strtolower($setup->direction)).' opportunity',
            '{delta_marker}' => $positive ? '🟢 ▲' : '🔴 ▼',
            '{delta_label}' => $positive ? 'Positive Delta' : 'Negative Delta',
            '{delta_percentage}' => $deltaPercentage,
            '{volume}' => is_numeric($volume) ? number_format((float)$volume,0,'.',',') : 'unavailable',
            '{setup_id}' => (string) $setup->id, '{timeframe}' => strtoupper($setup->timeframe),
            '{entry}' => number_format($setup->entry, 2, '.', ''),
            '{stop_loss}' => number_format($setup->stop_loss, 2, '.', ''),
            '{tp1}' => number_format($setup->tp1, 2, '.', ''),
            '{tp2}' => number_format($setup->tp2, 2, '.', ''), '{score}' => (string) $setup->score,
            '{entry_basis}' => match($setup->strategy_version){
                'zone_absorption_v2'=>'H1 zone + confirmed M5 absorption',
                'h1_trend_range_v3'=>'H1 trend/range + M5 absorption + CVD + footprint',
                default=>'Weighted confluence '.$setup->score.'/100',
            },
            '{time}' => \Carbon\CarbonImmutable::instance($time)->utc()->format('d M Y H:i:s').' UTC',
            '{sent_time}' => now()->utc()->format('d M Y H:i:s').' UTC',
        ], $extra);
        // Escape substitutions, retaining only the trusted template's Telegram HTML markup.
        return strtr(config('alerts.templates.'.$template), array_map(
            fn ($value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $values));
    }
}

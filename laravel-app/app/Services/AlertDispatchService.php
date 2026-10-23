<?php

namespace App\Services;

use App\Models\AlertLog;
use Illuminate\Support\Facades\Mail;

class AlertDispatchService
{
    public function sendTest(): void
    {
        $this->dispatch('TEST', 'Test alert from GoldFlow Pro — connection OK');
    }

    public function dispatch(string $type, string $message, string $level = 'info', ?int $setupId = null,
        ?int $opportunityId = null): void
    {
        $channels = config('alerts.channels', ['telegram', 'email']);

        foreach ($channels as $channel) {
            $status = 'pending';
            $error  = null;

            try {
                match ($channel) {
                    'telegram' => $this->sendTelegram($message),
                    'email'    => $this->sendEmail($message, $level),
                    default    => null,
                };
                $status = 'sent';
            } catch (\Exception $e) {
                $status = 'failed';
                $error  = $e->getMessage();
            }

            AlertLog::create([
                'setup_id' => $setupId,
                'variable_opportunity_id' => $opportunityId,
                'channel'  => $channel,
                'type'     => $type,
                'message'  => $message,
                'status'   => $status,
                'error'    => $error,
            ]);
        }
    }

    private function sendTelegram(string $message): void
    {
        $token  = config('alerts.telegram_token');
        $chatId = config('alerts.telegram_chat_id');

        if (!$token || !$chatId) throw new \RuntimeException('Telegram not configured');

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['chat_id' => $chatId, 'text' => $message, 'parse_mode' => 'HTML']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) throw new \RuntimeException("Telegram API error {$code}: {$response}");
    }

    private function sendEmail(string $message, string $level): void
    {
        $to = config('alerts.alert_email');
        if (!$to) throw new \RuntimeException('Alert email not configured');

        Mail::raw($message, fn($m) => $m->to($to)->subject("[GoldFlow Pro] {$level} — XAUUSD"));
    }
}

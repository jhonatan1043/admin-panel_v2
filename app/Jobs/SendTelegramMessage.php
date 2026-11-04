<?php

namespace App\Jobs;

use App\Models\ClientLog;
use App\Models\Setting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envía un mensaje a Telegram fuera de la petición HTTP.
 *
 * La petición del panel/API responde al instante y este job hace la llamada a
 * Telegram en el worker de colas. Si hay un registro en client_logs, lo deja
 * en "sent" (con su telegram_message_id) o en "failed" con el motivo.
 */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    /** Reintentos ante caídas de red o límites de Telegram. */
    public int $tries = 3;

    /** Espera (s) entre reintentos. */
    public array $backoff = [5, 15];

    public function __construct(
        public string $chatId,
        public string $text,
        public ?int $logId = null,
    ) {
        // Cola propia para que los envíos no esperen detrás de otros trabajos.
        $this->onQueue('telegram');
    }

    public function handle(): void
    {
        // El token se lee al ejecutar (desde caché) y no viaja en el payload de la cola.
        $botToken = Setting::get('telegram_bot_token');
        if (! $botToken) {
            $this->markFailed('Panel sin bot de Telegram configurado.');

            return;
        }

        try {
            $resp = Http::timeout(10)->asForm()->post(
                "https://api.telegram.org/bot{$botToken}/sendMessage",
                ['chat_id' => $this->chatId, 'text' => $this->text]
            );
        } catch (\Throwable $e) {
            // Error de red: reintenta; en el último intento queda como fallido.
            if ($this->attempts() >= $this->tries) {
                $this->markFailed('No se pudo conectar con Telegram.');

                return;
            }
            $this->release($this->backoff[$this->attempts() - 1] ?? 15);

            return;
        }

        // Telegram limita la tasa (429): esperar lo que indique y reintentar.
        if ($resp->status() === 429 && $this->attempts() < $this->tries) {
            $this->release((int) ($resp->json('parameters.retry_after') ?? 5));

            return;
        }

        $messageId = $resp->json('result.message_id');
        if ($resp->successful() && $resp->json('ok') === true && is_int($messageId) && $messageId > 0) {
            $this->updateLog(['status' => 'sent', 'detail' => 'Entregado a Telegram.', 'telegram_message_id' => $messageId]);

            return;
        }

        $this->markFailed(
            $resp->successful()
                ? 'Telegram no devolvió un message_id válido.'
                : 'Telegram rechazó: '.($resp->json('description') ?? 'error desconocido')
        );
    }

    /** Si el job revienta por algo inesperado, el registro no queda "en cola" para siempre. */
    public function failed(?\Throwable $e): void
    {
        $this->markFailed('Error al procesar el envío.');
    }

    private function markFailed(string $detail): void
    {
        $this->updateLog(['status' => 'failed', 'detail' => $detail]);

        if (! $this->logId) {
            Log::warning('Envío a Telegram fallido', ['chat_id' => $this->chatId, 'detail' => $detail]);
        }
    }

    private function updateLog(array $attributes): void
    {
        if ($this->logId) {
            ClientLog::whereKey($this->logId)->update($attributes);
        }
    }
}

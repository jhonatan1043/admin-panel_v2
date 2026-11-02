<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Button;
use App\Models\ClientLog;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use OpenApi\Attributes as OA;

class MessagingController extends Controller
{
    #[OA\Post(
        path: '/companies/{company}/send',
        tags: ['Mensajería'],
        summary: 'Envía el texto de un botón al Telegram del cliente (notificación)',
        description: 'Envía, como notificación, el texto del botón al chat de Telegram del cliente, usando el bot del propio cliente. No controla ninguna pantalla.',
        parameters: [
            new OA\Parameter(name: 'company', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['button_id'],
                properties: [
                    new OA\Property(property: 'button_id', type: 'integer', example: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Mensaje enviado'),
            new OA\Response(response: 403, description: 'El cliente no está asignado a este operador'),
            new OA\Response(response: 422, description: 'Cliente sin Telegram configurado o datos inválidos'),
            new OA\Response(response: 502, description: 'Telegram rechazó o no respondió el envío'),
        ]
    )]
    public function send(Request $request, Company $company): JsonResponse
    {
        $user = $request->user();

        // Un operador solo puede enviar a sus clientes; el admin a cualquiera.
        abort_unless(
            $user->role === 'admin' || $company->operator_id === $user->id,
            403,
            'No tienes este cliente asignado.'
        );

        $data = $request->validate([
            'button_id' => ['required', 'integer', 'exists:buttons,id'],
        ]);

        $button = Button::findOrFail($data['button_id']);

        if (! $company->telegram_bot_token || ! $company->telegram_chat_id) {
            $this->log($company, $button, 'failed', 'Cliente sin Telegram configurado.');

            return response()->json([
                'ok' => false,
                'message' => 'El cliente no tiene Telegram configurado (bot token o chat id).',
            ], 422);
        }

        try {
            $resp = Http::timeout(10)->asForm()->post(
                "https://api.telegram.org/bot{$company->telegram_bot_token}/sendMessage",
                ['chat_id' => $company->telegram_chat_id, 'text' => $button->text]
            );
        } catch (\Throwable $e) {
            $this->log($company, $button, 'failed', 'No se pudo conectar con Telegram.');

            return response()->json([
                'ok' => false,
                'message' => 'No se pudo conectar con Telegram.',
            ], 502);
        }

        if ($resp->successful() && $resp->json('ok') === true) {
            $this->log($company, $button, 'sent', 'Entregado a Telegram.');

            return response()->json(['ok' => true, 'message' => 'Mensaje enviado.']);
        }

        $detail = 'Telegram rechazó: ' . ($resp->json('description') ?? 'error desconocido');
        $this->log($company, $button, 'failed', $detail);

        return response()->json(['ok' => false, 'message' => $detail], 502);
    }

    /** Registra un paso en la auditoría del cliente. */
    private function log(Company $company, Button $button, string $status, string $detail): void
    {
        ClientLog::create([
            'company_id' => $company->id,
            'operator_id' => auth()->id(),
            'direction' => 'out',
            'label' => $button->label,
            'text' => $button->text,
            'status' => $status,
            'detail' => $detail,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientLog;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TelegramWebhookController extends Controller
{
    #[OA\Post(
        path: '/telegram/webhook',
        tags: ['Mensajería'],
        summary: 'Recibe las respuestas del cliente desde Telegram',
        description: 'Telegram envía aquí cada mensaje del cliente. Se asocia por chat_id al cliente y queda en su detalle como mensaje entrante.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(
                        property: 'message',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'text', type: 'string', example: 'solicitud'),
                            new OA\Property(
                                property: 'chat',
                                type: 'object',
                                properties: [new OA\Property(property: 'id', type: 'integer', example: -1001234567890)]
                            ),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Recibido'),
        ]
    )]
    public function handle(Request $request): JsonResponse
    {
        // Verificación opcional del token secreto de Telegram.
        $secret = config('services.telegram.webhook_secret');
        if ($secret && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $secret) {
            return response()->json(['ok' => false], 403);
        }

        $message = $request->input('message') ?? $request->input('edited_message');
        $chatId = data_get($message, 'chat.id');
        // Id exacto del usuario que escribe (en chats privados coincide con chat.id).
        $messengerId = data_get($message, 'from.id');
        $text = data_get($message, 'text');

        // Siempre respondemos 200 a Telegram, aunque no haya coincidencia.
        if (($chatId || $messengerId) && $text !== null) {
            // Emparejamos por el messenger_id del usuario o por el chat_id del cliente.
            $company = Company::query()
                ->when($messengerId, fn ($q) => $q->orWhere('messenger_id', (string) $messengerId))
                ->when($chatId, fn ($q) => $q->orWhere('telegram_chat_id', (string) $chatId))
                ->first();

            if ($company) {
                // Guardamos el messenger_id real para que el envío del botón llegue
                // exactamente a este usuario y no a otro.
                if ($messengerId && (string) $messengerId !== (string) $company->messenger_id) {
                    $company->forceFill(['messenger_id' => (string) $messengerId])->save();
                }

                ClientLog::create([
                    'company_id' => $company->id,
                    'direction' => 'in',
                    'text' => $text,
                    'status' => 'received',
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }
}

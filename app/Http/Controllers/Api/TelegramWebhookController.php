<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientLog;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class TelegramWebhookController extends Controller
{
    #[OA\Get(
        path: '/telegram/latest-message',
        tags: ['Mensajería'],
        summary: 'Consulta el último mensaje enviado correctamente desde el panel',
        parameters: [
            new OA\Parameter(
                name: 'session_token',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'uuid')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Último mensaje enviado desde el panel, o null si todavía no hay envíos',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'mensaje', type: 'string'),
                                new OA\Property(property: 'message_id', type: 'integer'),
                            ]
                        ),
                    ]
                )
            ),
        ]
    )]
    public function latest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['required', 'uuid'],
        ]);
        $company = Company::query()->where('loading_token', $data['session_token'])->first();
        if (! $company) {
            return response()->json(['error' => 'La solicitud no existe'], 404);
        }

        $message = ClientLog::query()
            ->where('company_id', $company->id)
            ->where('direction', 'out')
            ->where('status', 'sent')
            ->whereNotNull('telegram_message_id')
            ->latest('id')
            ->first();

        return response()->json([
            'data' => $message && ! $message->used_at ? [
                'mensaje' => $message->text,
                'message_id' => $message->telegram_message_id,
            ] : null,
        ])->header('Cache-Control', 'no-store');
    }

    #[OA\Post(
        path: '/telegram/use-message',
        tags: ['Mensajería'],
        summary: 'Marca como usado el último mensaje enviado desde el panel',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['session_token', 'message_id'],
                properties: [
                    new OA\Property(property: 'session_token', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'message_id', type: 'integer', example: 42),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Indica si el mensaje todavía era el último y quedó marcado como usado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [new OA\Property(property: 'used', type: 'boolean', example: true)]
                        ),
                    ]
                )
            ),
        ]
    )]
    public function useLatest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['required', 'uuid'],
            'message_id' => ['required', 'integer', 'min:1'],
        ]);
        $company = Company::query()->where('loading_token', $data['session_token'])->first();
        if (! $company) {
            return response()->json(['error' => 'La solicitud no existe'], 404);
        }

        $used = DB::transaction(function () use ($data, $company): bool {
            $latest = ClientLog::query()
                ->where('company_id', $company->id)
                ->where('direction', 'out')
                ->where('status', 'sent')
                ->whereNotNull('telegram_message_id')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $latest || (int) $latest->telegram_message_id !== (int) $data['message_id'] || $latest->used_at) {
                return false;
            }

            $latest->used_at = now();
            $latest->save();

            return true;
        });

        return response()->json(['data' => ['used' => $used]]);
    }

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

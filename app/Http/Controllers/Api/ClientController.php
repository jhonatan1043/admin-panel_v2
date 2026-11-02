<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ClientController extends Controller
{
    // GET /api/clients — lista con estado calculado por tiempo.
    #[OA\Get(
        path: '/clients',
        tags: ['Clientes'],
        summary: 'Lista de clientes con su estado (verde/amarillo/gris/rojo)',
        responses: [
            new OA\Response(response: 200, description: 'Listado de clientes'),
        ]
    )]
    public function index(): JsonResponse
    {
        $clients = Company::with(['messageTemplates' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->orderByDesc('last_activity_at')
            ->get()
            ->map(fn (Company $c) => $this->present($c));

        return response()->json(['data' => $clients]);
    }

    // POST /api/clients — registra/refresca un cliente y lo pone en verde.
    #[OA\Post(
        path: '/clients',
        tags: ['Clientes'],
        summary: 'Registra un cliente nuevo (el registro se genera internamente)',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['empresa', 'fecha'],
                properties: [
                    new OA\Property(property: 'empresa', type: 'string', example: 'Acme S.A.'),
                    new OA\Property(property: 'fecha', type: 'string', format: 'date-time', example: '2026-11-02 09:30'),
                    new OA\Property(property: 'name', type: 'string', example: 'Juan Pérez'),
                    new OA\Property(property: 'telegram_chat_id', type: 'string', example: '123456789'),
                    new OA\Property(property: 'messenger_id', type: 'string', description: 'Id del usuario de Telegram; destino exacto del botón.', example: '123456789'),
                    new OA\Property(property: 'data', type: 'object', description: 'Cualquier otro dato libre', example: ['plan' => 'premium', 'origen' => 'web']),
                    new OA\Property(property: 'registro', type: 'string', readOnly: true, description: 'Generado por el servidor; se devuelve en la respuesta.', example: 'REG-0001'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Cliente creado'),
            new OA\Response(response: 200, description: 'Cliente actualizado'),
            new OA\Response(response: 422, description: 'Datos inválidos'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        // El registro NO se recibe: es un identificador interno que genera el servidor.
        $data = $request->validate([
            'empresa' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date'],
            'name' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'messenger_id' => ['nullable', 'string', 'max:255'],
            'data' => ['nullable', 'array'],
        ]);

        $client = new Company($data);
        $client->last_activity_at = now();
        $client->save();

        // Registro interno derivado del id, estable y único.
        $client->registro = 'REG-'.str_pad((string) $client->id, 4, '0', STR_PAD_LEFT);
        $client->save();

        return response()->json(
            ['data' => $this->present($client->load('messageTemplates'))],
            201
        );
    }

    private function present(Company $c): array
    {
        return [
            'registro' => $c->registro,
            'name' => $c->name,
            'empresa' => $c->empresa,
            'messenger_id' => $c->messenger_id,
            'fecha' => $c->fecha?->toIso8601String(),
            'status' => $c->current_status,
            'last_activity_at' => $c->last_activity_at?->toIso8601String(),
            'minutes_since_activity' => $c->minutesSinceActivity(),
            'data' => $c->data,
            'buttons' => $c->messageTemplates->map(fn ($t) => [
                'label' => $t->label,
                'body' => $t->body,
            ])->values(),
        ];
    }
}

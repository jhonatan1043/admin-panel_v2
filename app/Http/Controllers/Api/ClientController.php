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
        summary: 'Registra un cliente nuevo o refresca su actividad',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['registro', 'empresa', 'fecha'],
                properties: [
                    new OA\Property(property: 'registro', type: 'string', example: 'REG-0001'),
                    new OA\Property(property: 'empresa', type: 'string', example: 'Acme S.A.'),
                    new OA\Property(property: 'fecha', type: 'string', format: 'date-time', example: '2026-11-02 09:30'),
                    new OA\Property(property: 'name', type: 'string', example: 'Juan Pérez'),
                    new OA\Property(property: 'telegram_chat_id', type: 'string', example: '123456789'),
                    new OA\Property(property: 'data', type: 'object', description: 'Cualquier otro dato libre', example: ['plan' => 'premium', 'origen' => 'web']),
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
        $data = $request->validate([
            'registro' => ['required', 'string', 'max:255'],
            'empresa' => ['required', 'string', 'max:255'],
            'fecha' => ['required', 'date'],
            'name' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id' => ['nullable', 'string', 'max:255'],
            'data' => ['nullable', 'array'],
        ]);

        $client = Company::firstOrNew(['registro' => $data['registro']]);
        $client->fill($data);
        $client->last_activity_at = now();
        $created = ! $client->exists;
        $client->save();

        return response()->json(
            ['data' => $this->present($client->load('messageTemplates'))],
            $created ? 201 : 200
        );
    }

    private function present(Company $c): array
    {
        return [
            'registro' => $c->registro,
            'name' => $c->name,
            'empresa' => $c->empresa,
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

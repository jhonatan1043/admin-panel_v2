<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class ClientController extends Controller
{
    /** Clientes por página en GET /api/clients. */
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    // GET /api/clients — lista con estado calculado por tiempo.
    #[OA\Get(
        path: '/clients',
        tags: ['Clientes'],
        summary: 'Lista paginada de clientes con su estado (verde/amarillo/gris/rojo)',
        description: 'Ordenada por actividad más reciente. Usa page y per_page para recorrer las páginas.',
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: self::MAX_PER_PAGE, default: self::DEFAULT_PER_PAGE)),
        ],
        responses: [
            new OA\Response(response: 429, description: 'Demasiadas solicitudes (límite por IP)'),
            new OA\Response(
                response: 200,
                description: 'Página de clientes',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(
                            property: 'meta',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'current_page', type: 'integer', example: 1),
                                new OA\Property(property: 'per_page', type: 'integer', example: 50),
                                new OA\Property(property: 'total', type: 'integer', example: 120),
                                new OA\Property(property: 'last_page', type: 'integer', example: 3),
                            ]
                        ),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? self::DEFAULT_PER_PAGE);

        // Se cachea la consulta de cada página (no la respuesta): el estado por tiempo
        // (verde/amarillo/…) se calcula al momento en present(), así nunca queda viejo.
        // La versión cambia al crear/editar/borrar un cliente o una plantilla.
        // Prefijo "list2": formato de arreglos (las entradas viejas con modelos se ignoran).
        $key = 'api.clients.list2.v'.Company::apiListVersion().".p{$page}.n{$perPage}";

        // En caché solo van arreglos (filas crudas), nunca modelos: la config
        // cache.serializable_classes = false impide deserializar objetos PHP.
        $result = Cache::remember($key, now()->addMinutes(5), function () use ($page, $perPage) {
            $companies = Company::with(['messageTemplates' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
                ->orderByDesc('last_activity_at')
                ->orderByDesc('id') // orden estable entre páginas
                ->forPage($page, $perPage)
                ->get();

            return [
                'total' => Company::count(),
                'items' => $companies->map(fn (Company $c) => [
                    'attributes' => $c->getAttributes(),
                    'templates' => $c->messageTemplates->map->getAttributes()->all(),
                ])->all(),
            ];
        });

        // Reconstruye los modelos (con sus casts) desde los arreglos cacheados.
        $items = collect($result['items'])->map(function (array $row) {
            $company = Company::hydrate([$row['attributes']])->first();
            $company->setRelation('messageTemplates', MessageTemplate::hydrate($row['templates']));

            return $company;
        });

        return response()->json([
            'data' => $items->map(fn (Company $c) => $this->present($c))->values(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
        ]);
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
            new OA\Response(response: 429, description: 'Demasiadas solicitudes (límite por IP)'),
            new OA\Response(
                response: 201,
                description: 'Cliente creado con identificador privado para esta solicitud',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(
                                    property: 'loading_token',
                                    type: 'string',
                                    format: 'uuid',
                                    description: 'Token privado para consultar las respuestas de esta solicitud.'
                                ),
                            ]
                        ),
                    ]
                )
            ),
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
        $client->forceFill(['loading_token' => (string) Str::uuid()]);
        $client->last_activity_at = now();
        $client->save();

        // Registro interno derivado del id, estable y único.
        $client->registro = 'REG-'.str_pad((string) $client->id, 4, '0', STR_PAD_LEFT);
        $client->save();

        return response()->json(
            ['data' => array_merge(
                $this->present($client->load('messageTemplates')),
                ['loading_token' => $client->loading_token]
            )],
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

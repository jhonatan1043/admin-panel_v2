<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Button;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ButtonController extends Controller
{
    // GET /api/buttons — catálogo de botones creados (id, nombre y mensaje).
    #[OA\Get(
        path: '/buttons',
        tags: ['Botones'],
        summary: 'Lista los botones creados (id, nombre y mensaje)',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Listado de botones',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 1),
                                    new OA\Property(property: 'nombre', type: 'string', example: 'Licencia'),
                                    new OA\Property(property: 'mensaje', type: 'string', example: 'Tu licencia está activa.'),
                                    new OA\Property(property: 'url', type: 'string', nullable: true, description: 'URL interna del botón.', example: 'https://interno/accion'),
                                    new OA\Property(property: 'vence_segundos', type: 'integer', nullable: true, description: 'Segundos de vencimiento; null = no vence.', example: 50),
                                ]
                            )
                        ),
                    ]
                )
            ),
        ]
    )]
    public function index(): JsonResponse
    {
        $buttons = Button::orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Button $b) => [
                'id' => $b->id,
                'nombre' => $b->label,
                'mensaje' => $b->text,
                'url' => $b->url,
                'vence_segundos' => $b->expires_seconds,
            ]);

        return response()->json(['data' => $buttons]);
    }
}

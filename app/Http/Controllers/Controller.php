<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Panel Admin API',
    description: 'API del panel de call center: clientes (registros), estado por tiempo y botones.'
)]
#[OA\Server(url: '/api', description: 'Servidor local')]
abstract class Controller
{
    //
}

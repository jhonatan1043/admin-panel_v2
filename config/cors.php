<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | El JavaScript de integración se agrega en páginas alojadas en OTRO dominio
    | (el hosting del cliente) y consume esta API. Por eso se permite el acceso
    | de cualquier origen a las rutas públicas de integración y al archivo JS.
    |
    */

    // Libre para cualquier origen en TODAS las rutas.
    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Sin credenciales: los envíos públicos no usan cookies, así se puede usar '*'.
    'supports_credentials' => false,

];

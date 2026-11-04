<?php

// Devuelve 0 si la base de datos MySQL acepta conexiones, 1 en caso contrario.
try {
    new PDO(
        sprintf('mysql:host=%s;port=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '3306'),
        getenv('DB_USERNAME') ?: 'root',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_TIMEOUT => 3]
    );
    exit(0);
} catch (Throwable $e) {
    exit(1);
}

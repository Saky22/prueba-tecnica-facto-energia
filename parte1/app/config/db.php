<?php

declare(strict_types=1);

return [
    'class'    => 'yii\db\Connection',
    'dsn'      => sprintf(
        'sqlsrv:Server=%s,%s;Database=%s;TrustServerCertificate=1',
        getenv('DB_HOST')     ?: 'db',
        getenv('DB_PORT')     ?: '1433',
        getenv('DB_DATABASE') ?: 'lecturas'
    ),
    'username' => getenv('DB_USER')     ?: 'sa',
    'password' => getenv('DB_PASSWORD') ?: 'Prueba.Tecnica.2026',
    'charset'  => 'utf8',
    'attributes' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
];

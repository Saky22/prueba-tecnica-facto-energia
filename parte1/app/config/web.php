<?php

declare(strict_types=1);

use App\Application\RegistrarLectura\RegistrarLecturaHandler;
use App\Domain\Contrato\ContratoRepository;
use App\Domain\Lectura\LecturaRepository;
use App\Infrastructure\Persistence\SqlServerContratoRepository;
use App\Infrastructure\Persistence\SqlServerLecturaRepository;

/**
 * Yii2 application configuration.
 *
 * DI wiring: the container binds domain ports (interfaces) to their
 * SQL Server infrastructure adapters. The handler is registered as a
 * component so the controller can resolve it via Yii::$app->lecturaHandler.
 */

// Build PDO from db config (shared across repositories)
$dbConfig = require __DIR__ . '/db.php';

$pdo = new PDO(
    $dbConfig['dsn'],
    $dbConfig['username'],
    $dbConfig['password'],
    $dbConfig['attributes'] ?? []
);

$contratoRepo = new SqlServerContratoRepository($pdo);
$lecturaRepo  = new SqlServerLecturaRepository($pdo);
$handler      = new RegistrarLecturaHandler($contratoRepo, $lecturaRepo);

return [
    'id'         => 'prueba-tecnica-parte1',
    'basePath'   => dirname(__DIR__),
    'bootstrap'  => ['log'],

    'aliases' => [
        '@app' => dirname(__DIR__),
    ],

    'components' => [
        'request' => [
            'parsers' => [
                'application/json' => 'yii\web\JsonParser',
            ],
            'cookieValidationKey' => 'not-used-in-api',
        ],
        'response' => [
            'format' => yii\web\Response::FORMAT_JSON,
        ],
        'log' => [
            'targets' => [
                [
                    'class'  => 'yii\log\FileTarget',
                    'levels' => ['error', 'warning'],
                ],
            ],
        ],
        'errorHandler' => [
            'class' => 'yii\web\ErrorHandler',
        ],
        'urlManager' => [
            'enablePrettyUrl'     => true,
            'showScriptName'      => false,
            'enableStrictParsing' => true,
            'rules' => [
                ['class' => 'yii\web\UrlRule', 'pattern' => 'api/lecturas', 'route' => 'lectura/create', 'verb' => 'POST'],
            ],
        ],
        // Application-level service: the use case handler
        'lecturaHandler' => $handler,
    ],
];

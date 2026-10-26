<?php

declare(strict_types=1);

use App\Application\RegistrarLectura\RegistrarLecturaHandler;
use App\Infrastructure\Persistence\SqlServerContratoRepository;
use App\Infrastructure\Persistence\SqlServerLecturaRepository;

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
    'controllerNamespace' => 'App\\Infrastructure\\Http',

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
        'lecturaHandler' => $handler,
    ],
];

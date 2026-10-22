<?php

declare(strict_types=1);

// Autoloader — Composer
require __DIR__ . '/../vendor/autoload.php';

// Yii framework bootstrap
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';

$config = require __DIR__ . '/../config/web.php';

(new yii\web\Application($config))->run();

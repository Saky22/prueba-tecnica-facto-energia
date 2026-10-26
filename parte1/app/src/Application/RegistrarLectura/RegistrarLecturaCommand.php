<?php

declare(strict_types=1);

namespace App\Application\RegistrarLectura;

final class RegistrarLecturaCommand
{
    public function __construct(
        public readonly string $cups,
        public readonly string $fechaLectura,
        public readonly int $lecturaKwh,
        public readonly string $tipo,
    ) {}
}

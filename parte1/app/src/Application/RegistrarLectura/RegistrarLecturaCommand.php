<?php

declare(strict_types=1);

namespace App\Application\RegistrarLectura;

/**
 * Input DTO for the RegistrarLectura use case.
 * Carries raw/validated primitive values from the HTTP adapter.
 */
final class RegistrarLecturaCommand
{
    public function __construct(
        public readonly string $cups,
        public readonly string $fechaLectura,
        public readonly int $lecturaKwh,
        public readonly string $tipo,
    ) {}
}

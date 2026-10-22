<?php

declare(strict_types=1);

namespace App\Domain\Lectura\Exception;

use RuntimeException;

final class FechaFuturaException extends RuntimeException
{
    public function __construct(string $fecha)
    {
        parent::__construct(
            sprintf('La fecha de lectura %s es futura. No se aceptan lecturas con fecha posterior a hoy.', $fecha)
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Contrato\Exception;

use RuntimeException;

final class FechaFueraVigenciaException extends RuntimeException
{
    public function __construct(string $cups, string $fecha)
    {
        parent::__construct(
            sprintf(
                'La fecha %s está fuera del periodo de vigencia de cualquier contrato para el CUPS %s.',
                $fecha,
                $cups
            )
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Contrato\Exception;

use RuntimeException;

final class ContratoBajaException extends RuntimeException
{
    public function __construct(string $cups)
    {
        parent::__construct(
            sprintf('El contrato para el CUPS %s está dado de baja. La lectura no se ha guardado.', $cups)
        );
    }
}

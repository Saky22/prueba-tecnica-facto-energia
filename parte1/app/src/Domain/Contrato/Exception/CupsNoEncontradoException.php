<?php

declare(strict_types=1);

namespace App\Domain\Contrato\Exception;

use RuntimeException;

final class CupsNoEncontradoException extends RuntimeException
{
    public function __construct(string $cups)
    {
        parent::__construct(
            sprintf('No existe ningún contrato para el CUPS %s.', $cups)
        );
    }
}

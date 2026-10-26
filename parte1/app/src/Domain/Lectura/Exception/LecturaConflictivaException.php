<?php

declare(strict_types=1);

namespace App\Domain\Lectura\Exception;

use RuntimeException;

/**
 * Misma lectura (contrato, fecha, tipo) con otro valor de kWh: no es un reintento.
 */
final class LecturaConflictivaException extends RuntimeException
{
    public function __construct(
        string $cups,
        string $fechaLectura,
        string $tipo,
        public readonly int $lecturaExistenteId,
        public readonly int $kwhExistente,
        int $kwhRecibido,
    ) {
        parent::__construct(sprintf(
            'Ya existe una lectura %s del CUPS %s para %s con %d kWh (recibido: %d kWh). La lectura no se ha guardado.',
            $tipo,
            $cups,
            $fechaLectura,
            $kwhExistente,
            $kwhRecibido
        ));
    }
}

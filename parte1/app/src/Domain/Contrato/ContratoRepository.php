<?php

declare(strict_types=1);

namespace App\Domain\Contrato;

use DateTimeImmutable;

interface ContratoRepository
{
    /**
     * Contratos del CUPS ordenados por fecha_alta; vacío si no existe.
     *
     * @return Contrato[]
     */
    public function findByCups(Cups $cups): array;
}

<?php

declare(strict_types=1);

namespace App\Domain\Contrato;

use DateTimeImmutable;

/**
 * Port (output) — persistence abstraction for Contrato.
 * Infrastructure adapters must implement this interface.
 */
interface ContratoRepository
{
    /**
     * Finds all contracts associated with a CUPS, ordered by fecha_alta ascending.
     * Returns an empty array when the CUPS does not exist.
     *
     * @return Contrato[]
     */
    public function findByCups(Cups $cups): array;
}

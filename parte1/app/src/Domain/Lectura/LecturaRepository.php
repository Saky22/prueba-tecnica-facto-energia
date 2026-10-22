<?php

declare(strict_types=1);

namespace App\Domain\Lectura;

use DateTimeImmutable;

/**
 * Port (output) — persistence abstraction for Lectura.
 */
interface LecturaRepository
{
    /**
     * Persists a new reading and returns it with its generated id.
     */
    public function save(Lectura $lectura): Lectura;

    /**
     * Finds an existing reading by its natural idempotency key:
     * (contrato_id, fecha_lectura, lectura_kwh, tipo).
     * Returns null when no match is found.
     */
    public function findDuplicado(
        int $contratoId,
        DateTimeImmutable $fechaLectura,
        LecturaKwh $lecturaKwh,
        TipoLectura $tipo
    ): ?Lectura;
}

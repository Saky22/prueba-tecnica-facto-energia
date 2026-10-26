<?php

declare(strict_types=1);

namespace App\Domain\Lectura;

use DateTimeImmutable;

interface LecturaRepository
{
    public function save(Lectura $lectura): Lectura;

    /**
     * Busca por (contrato_id, fecha_lectura, tipo). Dentro de transaccional() la clave
     * queda bloqueada hasta el commit.
     */
    public function findByClave(int $contratoId, DateTimeImmutable $fechaLectura, TipoLectura $tipo): ?Lectura;

    /**
     * @template T
     * @param callable(): T $operacion
     * @return T
     */
    public function transaccional(callable $operacion): mixed;
}

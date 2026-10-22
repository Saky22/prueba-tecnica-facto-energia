<?php

declare(strict_types=1);

namespace App\Domain\Lectura;

use DateTimeImmutable;

/**
 * Aggregate Root for the Lectura bounded context.
 * Represents a meter reading received from a distributor.
 */
final class Lectura
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $contratoId,
        private readonly DateTimeImmutable $fechaLectura,
        private readonly LecturaKwh $lecturaKwh,
        private readonly TipoLectura $tipo,
        private readonly DateTimeImmutable $fechaRecepcion,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function contratoId(): int
    {
        return $this->contratoId;
    }

    public function fechaLectura(): DateTimeImmutable
    {
        return $this->fechaLectura;
    }

    public function lecturaKwh(): LecturaKwh
    {
        return $this->lecturaKwh;
    }

    public function tipo(): TipoLectura
    {
        return $this->tipo;
    }

    public function fechaRecepcion(): DateTimeImmutable
    {
        return $this->fechaRecepcion;
    }

    /**
     * Returns true if this reading matches the natural idempotency key
     * of another reading: same contrato, date, kwh and type.
     */
    public function esDuplicadoDe(self $other): bool
    {
        return $this->contratoId === $other->contratoId
            && $this->fechaLectura->format('Y-m-d') === $other->fechaLectura->format('Y-m-d')
            && $this->lecturaKwh->equals($other->lecturaKwh)
            && $this->tipo->equals($other->tipo);
    }
}

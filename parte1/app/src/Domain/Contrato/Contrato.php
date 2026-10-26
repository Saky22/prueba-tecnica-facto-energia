<?php

declare(strict_types=1);

namespace App\Domain\Contrato;

use DateTimeImmutable;

final class Contrato
{
    public function __construct(
        private readonly int $id,
        private readonly Cups $cups,
        private readonly string $estado,
        private readonly DateTimeImmutable $fechaAlta,
        private readonly ?DateTimeImmutable $fechaBaja,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function cups(): Cups
    {
        return $this->cups;
    }

    public function estado(): string
    {
        return $this->estado;
    }

    /**
     * fecha_alta <= fecha <= fecha_baja (o sin fecha de baja).
     * Un contrato hoy de baja sigue siendo el vigente para fechas anteriores a su baja.
     */
    public function estaVigenteEn(DateTimeImmutable $fecha): bool
    {
        if ($fecha < $this->fechaAlta) {
            return false;
        }

        if ($this->fechaBaja !== null && $fecha > $this->fechaBaja) {
            return false;
        }

        return true;
    }

    public function estaDeBajaEn(DateTimeImmutable $fecha): bool
    {
        return $this->estado === 'baja' && $this->estaVigenteEn($fecha);
    }
}

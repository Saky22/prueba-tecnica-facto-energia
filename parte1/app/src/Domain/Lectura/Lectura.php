<?php

declare(strict_types=1);

namespace App\Domain\Lectura;

use DateTimeImmutable;

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
}

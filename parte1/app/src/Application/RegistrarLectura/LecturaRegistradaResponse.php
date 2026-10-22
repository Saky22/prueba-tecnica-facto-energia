<?php

declare(strict_types=1);

namespace App\Application\RegistrarLectura;

/**
 * Output DTO returned by RegistrarLecturaHandler.
 */
final class LecturaRegistradaResponse
{
    public function __construct(
        public readonly int $id,
        public readonly string $cups,
        public readonly int $contratoId,
        public readonly string $fechaLectura,
        public readonly int $lecturaKwh,
        public readonly string $tipo,
        public readonly string $fechaRecepcion,
        public readonly bool $duplicado = false,
    ) {}

    public function toArray(): array
    {
        $data = [
            'id'              => $this->id,
            'cups'            => $this->cups,
            'contrato_id'     => $this->contratoId,
            'fecha_lectura'   => $this->fechaLectura,
            'lectura_kwh'     => $this->lecturaKwh,
            'tipo'            => $this->tipo,
            'fecha_recepcion' => $this->fechaRecepcion,
        ];

        if ($this->duplicado) {
            $data['duplicado'] = true;
        }

        return $data;
    }
}

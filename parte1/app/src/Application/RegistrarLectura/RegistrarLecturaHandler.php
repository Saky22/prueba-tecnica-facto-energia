<?php

declare(strict_types=1);

namespace App\Application\RegistrarLectura;

use App\Domain\Contrato\ContratoRepository;
use App\Domain\Contrato\Cups;
use App\Domain\Contrato\Exception\ContratoBajaException;
use App\Domain\Contrato\Exception\CupsNoEncontradoException;
use App\Domain\Contrato\Exception\FechaFueraVigenciaException;
use App\Domain\Lectura\Exception\FechaFuturaException;
use App\Domain\Lectura\Exception\LecturaConflictivaException;
use App\Domain\Lectura\Lectura;
use App\Domain\Lectura\LecturaKwh;
use App\Domain\Lectura\LecturaRepository;
use App\Domain\Lectura\TipoLectura;
use DateTimeImmutable;

/**
 * Caso de uso: registrar una lectura enviada por la distribuidora.
 *
 * Idempotencia por (contrato, fecha, tipo):
 *  - mismo kWh  -> reintento, se devuelve la lectura existente (duplicado = true)
 *  - otro kWh   -> LecturaConflictivaException, no se guarda
 */
final class RegistrarLecturaHandler
{
    public function __construct(
        private readonly ContratoRepository $contratoRepository,
        private readonly LecturaRepository $lecturaRepository,
    ) {}

    public function handle(RegistrarLecturaCommand $command): LecturaRegistradaResponse
    {
        $cups         = Cups::fromString($command->cups);
        $fechaLectura = new DateTimeImmutable($command->fechaLectura);
        $lecturaKwh   = LecturaKwh::fromInt($command->lecturaKwh);
        $tipo         = TipoLectura::fromString($command->tipo);
        $today        = new DateTimeImmutable('today');

        if ($fechaLectura > $today) {
            throw new FechaFuturaException($command->fechaLectura);
        }

        // Contrato vigente en la fecha de la lectura (un CUPS puede tener varios titulares)
        $contratos = $this->contratoRepository->findByCups($cups);

        if (empty($contratos)) {
            throw new CupsNoEncontradoException($command->cups);
        }

        $contratoVigente = null;
        foreach ($contratos as $contrato) {
            if ($contrato->estaVigenteEn($fechaLectura)) {
                $contratoVigente = $contrato;
                break;
            }
        }

        if ($contratoVigente === null) {
            foreach ($contratos as $contrato) {
                if ($contrato->estaDeBajaEn($fechaLectura)) {
                    throw new ContratoBajaException($command->cups);
                }
            }

            throw new FechaFueraVigenciaException($command->cups, $command->fechaLectura);
        }

        if ($contratoVigente->estado() === 'baja') {
            throw new ContratoBajaException($command->cups);
        }

        // Comprobación e inserción en la misma transacción: findByClave bloquea la clave
        // y un reintento concurrente espera en lugar de insertar otra vez.
        return $this->lecturaRepository->transaccional(function () use ($contratoVigente, $fechaLectura, $lecturaKwh, $tipo, $cups, $command): LecturaRegistradaResponse {
            $existente = $this->lecturaRepository->findByClave($contratoVigente->id(), $fechaLectura, $tipo);

            if ($existente !== null) {
                if ($existente->lecturaKwh()->equals($lecturaKwh)) {
                    return $this->buildResponse($existente, $cups->value(), duplicado: true);
                }

                throw new LecturaConflictivaException(
                    $cups->value(),
                    $command->fechaLectura,
                    $tipo->value(),
                    (int) $existente->id(),
                    $existente->lecturaKwh()->value(),
                    $lecturaKwh->value(),
                );
            }

            $saved = $this->lecturaRepository->save(new Lectura(
                id: null,
                contratoId: $contratoVigente->id(),
                fechaLectura: $fechaLectura,
                lecturaKwh: $lecturaKwh,
                tipo: $tipo,
                fechaRecepcion: new DateTimeImmutable(),
            ));

            return $this->buildResponse($saved, $cups->value(), duplicado: false);
        });
    }

    private function buildResponse(Lectura $lectura, string $cups, bool $duplicado): LecturaRegistradaResponse
    {
        return new LecturaRegistradaResponse(
            id: $lectura->id(),
            cups: $cups,
            contratoId: $lectura->contratoId(),
            fechaLectura: $lectura->fechaLectura()->format('Y-m-d'),
            lecturaKwh: $lectura->lecturaKwh()->value(),
            tipo: $lectura->tipo()->value(),
            fechaRecepcion: $lectura->fechaRecepcion()->format('Y-m-d\TH:i:s'),
            duplicado: $duplicado,
        );
    }
}

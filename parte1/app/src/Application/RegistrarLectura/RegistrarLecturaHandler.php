<?php

declare(strict_types=1);

namespace App\Application\RegistrarLectura;

use App\Domain\Contrato\ContratoRepository;
use App\Domain\Contrato\Cups;
use App\Domain\Contrato\Exception\ContratoBajaException;
use App\Domain\Contrato\Exception\CupsNoEncontradoException;
use App\Domain\Contrato\Exception\FechaFueraVigenciaException;
use App\Domain\Lectura\Exception\FechaFuturaException;
use App\Domain\Lectura\Lectura;
use App\Domain\Lectura\LecturaKwh;
use App\Domain\Lectura\LecturaRepository;
use App\Domain\Lectura\TipoLectura;
use DateTimeImmutable;

/**
 * Application Service (Use Case handler).
 *
 * Orchestrates domain objects and ports to fulfil the "register a reading" use case.
 * Does NOT know about HTTP, Yii2 or SQL Server — only domain and port interfaces.
 *
 * Validation order (fail-fast):
 *  1. Build Value Objects (format validation happens inside constructors)
 *  2. Fecha futura check
 *  3. Resolve contrato for CUPS + date (CUPS_NO_ENCONTRADO / CONTRATO_BAJA / FECHA_FUERA_VIGENCIA)
 *  4. Idempotency check → return existing with duplicado=true
 *  5. Persist and return new reading
 */
final class RegistrarLecturaHandler
{
    public function __construct(
        private readonly ContratoRepository $contratoRepository,
        private readonly LecturaRepository $lecturaRepository,
    ) {}

    public function handle(RegistrarLecturaCommand $command): LecturaRegistradaResponse
    {
        // Step 1: Build value objects (throws InvalidArgumentException on bad format)
        $cups         = Cups::fromString($command->cups);
        $fechaLectura = new DateTimeImmutable($command->fechaLectura);
        $lecturaKwh   = LecturaKwh::fromInt($command->lecturaKwh);
        $tipo         = TipoLectura::fromString($command->tipo);
        $today        = new DateTimeImmutable('today');

        // Step 2: Fecha futura check
        if ($fechaLectura > $today) {
            throw new FechaFuturaException($command->fechaLectura);
        }

        // Step 3: Resolve vigent contract for CUPS + date
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
            // We have contracts for this CUPS but none covers this date — either
            // all are in baja on that date or the date is before any contract starts.
            // Disambiguate: check if any contract was in baja on that date.
            foreach ($contratos as $contrato) {
                if ($contrato->estaDeBajaEn($fechaLectura)) {
                    throw new ContratoBajaException($command->cups);
                }
            }

            throw new FechaFueraVigenciaException($command->cups, $command->fechaLectura);
        }

        // Step 4: Idempotency check
        $duplicado = $this->lecturaRepository->findDuplicado(
            $contratoVigente->id(),
            $fechaLectura,
            $lecturaKwh,
            $tipo
        );

        if ($duplicado !== null) {
            return $this->buildResponse($duplicado, $cups->value(), duplicado: true);
        }

        // Step 5: Persist new reading
        $nuevaLectura = new Lectura(
            id: null,
            contratoId: $contratoVigente->id(),
            fechaLectura: $fechaLectura,
            lecturaKwh: $lecturaKwh,
            tipo: $tipo,
            fechaRecepcion: new DateTimeImmutable(),
        );

        $saved = $this->lecturaRepository->save($nuevaLectura);

        return $this->buildResponse($saved, $cups->value(), duplicado: false);
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

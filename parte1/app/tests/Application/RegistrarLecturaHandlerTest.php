<?php

declare(strict_types=1);

namespace Tests\Application;

use App\Application\RegistrarLectura\RegistrarLecturaCommand;
use App\Application\RegistrarLectura\RegistrarLecturaHandler;
use App\Domain\Contrato\Contrato;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RegistrarLecturaHandlerTest extends TestCase
{
    private ContratoRepository&MockObject $contratoRepo;
    private LecturaRepository&MockObject $lecturaRepo;
    private RegistrarLecturaHandler $handler;

    protected function setUp(): void
    {
        $this->contratoRepo = $this->createMock(ContratoRepository::class);
        $this->lecturaRepo  = $this->createMock(LecturaRepository::class);
        $this->handler      = new RegistrarLecturaHandler($this->contratoRepo, $this->lecturaRepo);
    }

    public function test_happy_path_creates_lectura_and_returns_201_data(): void
    {
        $cups    = 'ES0904998698797309NB';
        $command = new RegistrarLecturaCommand($cups, '2026-01-15', 10849, 'real');

        $contrato = $this->makeContrato(1, $cups, 'activo', '2024-02-01', null);
        $this->contratoRepo->method('findByCups')->willReturn([$contrato]);

        $this->lecturaRepo->method('findDuplicado')->willReturn(null);
        $this->lecturaRepo->method('save')->willReturnCallback(
            fn(Lectura $l) => $this->makeLectura(42, $l)
        );

        $response = $this->handler->handle($command);

        $this->assertSame(42, $response->id);
        $this->assertSame($cups, $response->cups);
        $this->assertFalse($response->duplicado);
    }

    public function test_duplicate_returns_existing_with_duplicado_flag(): void
    {
        $cups    = 'ES0904998698797309NB';
        $command = new RegistrarLecturaCommand($cups, '2026-01-15', 10849, 'real');

        $contrato = $this->makeContrato(1, $cups, 'activo', '2024-02-01', null);
        $this->contratoRepo->method('findByCups')->willReturn([$contrato]);

        $existing = $this->makePersistedLectura(42, 1, '2026-01-15', 10849, 'real');
        $this->lecturaRepo->method('findDuplicado')->willReturn($existing);
        $this->lecturaRepo->expects($this->never())->method('save');

        $response = $this->handler->handle($command);

        $this->assertSame(42, $response->id);
        $this->assertTrue($response->duplicado);
    }

    public function test_cups_not_found_throws_exception(): void
    {
        $this->contratoRepo->method('findByCups')->willReturn([]);
        $this->expectException(CupsNoEncontradoException::class);

        $this->handler->handle(new RegistrarLecturaCommand('ES0904998698797309NB', '2026-01-15', 100, 'real'));
    }

    public function test_contrato_baja_throws_exception(): void
    {
        $cups     = 'ES0904229486628360ZC';
        $contrato = $this->makeContrato(5, $cups, 'baja', '2021-02-01', '2026-08-31');

        $this->contratoRepo->method('findByCups')->willReturn([$contrato]);
        $this->expectException(ContratoBajaException::class);

        // Reading date within the baja contract's vigency period
        $this->handler->handle(new RegistrarLecturaCommand($cups, '2026-06-01', 11324, 'real'));
    }

    public function test_fecha_fuera_vigencia_throws_exception(): void
    {
        $cups     = 'ES0904998698797309NB';
        $contrato = $this->makeContrato(1, $cups, 'activo', '2024-02-01', null);

        $this->contratoRepo->method('findByCups')->willReturn([$contrato]);
        $this->expectException(FechaFueraVigenciaException::class);

        // Reading date before fecha_alta
        $this->handler->handle(new RegistrarLecturaCommand($cups, '2023-01-01', 100, 'real'));
    }

    public function test_fecha_futura_throws_exception(): void
    {
        $this->contratoRepo->method('findByCups')->willReturn([
            $this->makeContrato(1, 'ES0904998698797309NB', 'activo', '2020-01-01', null),
        ]);
        $this->expectException(FechaFuturaException::class);

        $future = (new DateTimeImmutable('+1 year'))->format('Y-m-d');
        $this->handler->handle(new RegistrarLecturaCommand('ES0904998698797309NB', $future, 100, 'real'));
    }

    public function test_cups_with_two_contracts_resolves_correct_one(): void
    {
        // CUPS ES0904688281126584QG: contract 12 baja 2026-07-15, contract 24 active from 2026-07-16
        $cups     = 'ES0904688281126584QG';
        $oldBaja  = $this->makeContrato(12, $cups, 'baja', '2023-01-01', '2026-07-15');
        $newActivo = $this->makeContrato(24, $cups, 'activo', '2026-07-16', null);

        $this->contratoRepo->method('findByCups')->willReturn([$oldBaja, $newActivo]);
        $this->lecturaRepo->method('findDuplicado')->willReturn(null);
        $this->lecturaRepo->method('save')->willReturnCallback(
            fn(Lectura $l) => $this->makeLectura(99, $l)
        );

        $response = $this->handler->handle(new RegistrarLecturaCommand($cups, '2026-08-01', 25613, 'real'));

        // Must resolve to contract 24, not 12
        $this->assertSame(24, $response->contratoId);
    }

    // --- Helpers -------------------------------------------------------

    private function makeContrato(int $id, string $cups, string $estado, string $alta, ?string $baja): Contrato
    {
        return new Contrato(
            id: $id,
            cups: Cups::fromString($cups),
            estado: $estado,
            fechaAlta: new DateTimeImmutable($alta),
            fechaBaja: $baja !== null ? new DateTimeImmutable($baja) : null,
        );
    }

    private function makeLectura(int $id, Lectura $base): Lectura
    {
        return new Lectura(
            id: $id,
            contratoId: $base->contratoId(),
            fechaLectura: $base->fechaLectura(),
            lecturaKwh: $base->lecturaKwh(),
            tipo: $base->tipo(),
            fechaRecepcion: $base->fechaRecepcion(),
        );
    }

    private function makePersistedLectura(int $id, int $contratoId, string $fecha, int $kwh, string $tipo): Lectura
    {
        return new Lectura(
            id: $id,
            contratoId: $contratoId,
            fechaLectura: new DateTimeImmutable($fecha),
            lecturaKwh: LecturaKwh::fromInt($kwh),
            tipo: TipoLectura::fromString($tipo),
            fechaRecepcion: new DateTimeImmutable('2026-01-02T06:09:56'),
        );
    }
}

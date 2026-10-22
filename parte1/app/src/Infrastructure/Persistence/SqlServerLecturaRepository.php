<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Lectura\Lectura;
use App\Domain\Lectura\LecturaKwh;
use App\Domain\Lectura\LecturaRepository;
use App\Domain\Lectura\TipoLectura;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * SQL Server adapter for LecturaRepository.
 */
final class SqlServerLecturaRepository implements LecturaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(Lectura $lectura): Lectura
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dbo.lecturas (contrato_id, fecha_lectura, lectura_kwh, tipo, fecha_recepcion)
             OUTPUT INSERTED.id
             VALUES (:contrato_id, :fecha_lectura, :lectura_kwh, :tipo, :fecha_recepcion)'
        );

        $stmt->execute([
            ':contrato_id'     => $lectura->contratoId(),
            ':fecha_lectura'   => $lectura->fechaLectura()->format('Y-m-d'),
            ':lectura_kwh'     => $lectura->lecturaKwh()->value(),
            ':tipo'            => $lectura->tipo()->value(),
            ':fecha_recepcion' => $lectura->fechaRecepcion()->format('Y-m-d H:i:s'),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException('No se pudo obtener el id de la lectura insertada.');
        }

        // Return a new Lectura with the generated id
        return new Lectura(
            id: (int) $row['id'],
            contratoId: $lectura->contratoId(),
            fechaLectura: $lectura->fechaLectura(),
            lecturaKwh: $lectura->lecturaKwh(),
            tipo: $lectura->tipo(),
            fechaRecepcion: $lectura->fechaRecepcion(),
        );
    }

    public function findDuplicado(
        int $contratoId,
        DateTimeImmutable $fechaLectura,
        LecturaKwh $lecturaKwh,
        TipoLectura $tipo
    ): ?Lectura {
        $stmt = $this->pdo->prepare(
            'SELECT id, contrato_id, fecha_lectura, lectura_kwh, tipo, fecha_recepcion
               FROM dbo.lecturas
              WHERE contrato_id   = :contrato_id
                AND fecha_lectura = :fecha_lectura
                AND lectura_kwh   = :lectura_kwh
                AND tipo          = :tipo'
        );

        $stmt->execute([
            ':contrato_id'   => $contratoId,
            ':fecha_lectura' => $fechaLectura->format('Y-m-d'),
            ':lectura_kwh'   => $lecturaKwh->value(),
            ':tipo'          => $tipo->value(),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    private function hydrate(array $row): Lectura
    {
        return new Lectura(
            id: (int) $row['id'],
            contratoId: (int) $row['contrato_id'],
            fechaLectura: new DateTimeImmutable($row['fecha_lectura']),
            lecturaKwh: LecturaKwh::fromInt((int) $row['lectura_kwh']),
            tipo: TipoLectura::fromString($row['tipo']),
            fechaRecepcion: new DateTimeImmutable($row['fecha_recepcion']),
        );
    }
}

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
use Throwable;

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

        return new Lectura(
            id: (int) $row['id'],
            contratoId: $lectura->contratoId(),
            fechaLectura: $lectura->fechaLectura(),
            lecturaKwh: $lectura->lecturaKwh(),
            tipo: $lectura->tipo(),
            fechaRecepcion: $lectura->fechaRecepcion(),
        );
    }

    public function findByClave(int $contratoId, DateTimeImmutable $fechaLectura, TipoLectura $tipo): ?Lectura
    {
        // UPDLOCK + HOLDLOCK: bloquea la clave hasta el commit (evita la carrera entre reintentos).
        // TOP 1: los datos históricos ya traen un duplicado (contrato 3, 2026-06-01).
        $stmt = $this->pdo->prepare(
            'SELECT TOP 1 id, contrato_id, fecha_lectura, lectura_kwh, tipo, fecha_recepcion
               FROM dbo.lecturas WITH (UPDLOCK, HOLDLOCK)
              WHERE contrato_id   = :contrato_id
                AND fecha_lectura = :fecha_lectura
                AND tipo          = :tipo
              ORDER BY id'
        );

        $stmt->execute([
            ':contrato_id'   => $contratoId,
            ':fecha_lectura' => $fechaLectura->format('Y-m-d'),
            ':tipo'          => $tipo->value(),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function transaccional(callable $operacion): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $resultado = $operacion();
            $this->pdo->commit();

            return $resultado;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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

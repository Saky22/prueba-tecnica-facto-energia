<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Contrato\Contrato;
use App\Domain\Contrato\ContratoRepository;
use App\Domain\Contrato\Cups;
use DateTimeImmutable;
use PDO;

final class SqlServerContratoRepository implements ContratoRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return Contrato[]
     */
    public function findByCups(Cups $cups): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, cups, estado, fecha_alta, fecha_baja
               FROM dbo.contratos
              WHERE cups = :cups
              ORDER BY fecha_alta ASC'
        );

        $stmt->execute([':cups' => $cups->value()]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row) => $this->hydrate($row), $rows);
    }

    private function hydrate(array $row): Contrato
    {
        return new Contrato(
            id: (int) $row['id'],
            cups: Cups::fromString($row['cups']),
            estado: $row['estado'],
            fechaAlta: new DateTimeImmutable($row['fecha_alta']),
            fechaBaja: $row['fecha_baja'] !== null ? new DateTimeImmutable($row['fecha_baja']) : null,
        );
    }
}

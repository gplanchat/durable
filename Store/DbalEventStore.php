<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Gplanchat\Durable\Event\Event;

final class DbalEventStore implements EventStoreInterface
{
    private bool $recordedAtColumnEnsured = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'durable_events',
        /**
         * Taille max d'une page SQL keyset ({@see readStreamWithRecordedAt}). Défaut 500 ; tests peuvent baisser pour valider le multi-pages sans insérer des centaines d'événements.
         */
        private readonly int $readStreamPageSize = 500,
    ) {
        if ($this->readStreamPageSize < 1) {
            throw new \InvalidArgumentException('readStreamPageSize must be >= 1.');
        }
    }

    public function append(Event $event): void
    {
        $this->ensureRecordedAtColumnOnce();
        $row = EventSerializer::serialize($event);
        $recordedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->connection->insert($this->tableName, [
            'execution_id' => $row['execution_id'],
            'event_type' => $row['event_type'],
            'payload' => json_encode($row['payload'], \JSON_THROW_ON_ERROR),
            'recorded_at' => $recordedAt->format('Y-m-d H:i:s.u'),
        ], [
            'payload' => ParameterType::STRING,
        ]);
    }

    /**
     * @return \Generator<int, Event>
     */
    public function readStream(string $executionId): iterable
    {
        foreach ($this->readStreamWithRecordedAt($executionId) as $entry) {
            yield $entry['event'];
        }
    }

    /**
     * @return iterable<array{event: Event, recordedAt: \DateTimeImmutable|null}>
     */
    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        $this->ensureRecordedAtColumnOnce();
        $qTable = $this->connection->quoteIdentifier($this->tableName);
        $lastId = 0;
        while (true) {
            $sql = 'SELECT id, execution_id, event_type, payload, recorded_at FROM '.$qTable
                .' WHERE execution_id = ? AND id > ? ORDER BY id ASC LIMIT ?';
            $result = $this->connection->executeQuery(
                $sql,
                [$executionId, $lastId, $this->readStreamPageSize],
                [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
            );
            $rowsInPage = 0;
            foreach ($result->iterateAssociative() as $row) {
                ++$rowsInPage;
                $lastId = (int) $row['id'];
                unset($row['id']);
                $event = EventSerializer::deserialize($row);
                $recordedAt = null;
                if (isset($row['recorded_at']) && '' !== (string) $row['recorded_at']) {
                    try {
                        $recordedAt = new \DateTimeImmutable((string) $row['recorded_at'], new \DateTimeZone('UTC'));
                    } catch (\Exception) {
                        $recordedAt = null;
                    }
                }

                yield ['event' => $event, 'recordedAt' => $recordedAt];
            }
            if ($rowsInPage < $this->readStreamPageSize) {
                break;
            }
        }
    }

    public function countEventsInStream(string $executionId): int
    {
        $this->ensureRecordedAtColumnOnce();
        $sql = 'SELECT COUNT(*) FROM '.$this->connection->quoteIdentifier($this->tableName)
            .' WHERE execution_id = ?';

        $n = $this->connection->fetchOne(
            $sql,
            [$executionId],
            [ParameterType::STRING],
        );

        return (int) $n;
    }

    public function createSchema(): void
    {
        $schema = $this->connection->createSchemaManager();
        $table = new \Doctrine\DBAL\Schema\Table($this->tableName);
        $table->addColumn('id', 'bigint', ['autoincrement' => true]);
        $table->addColumn('execution_id', 'string', ['length' => 36]);
        $table->addColumn('event_type', 'string', ['length' => 255]);
        $table->addColumn('payload', 'text');
        $table->addColumn('recorded_at', 'datetime_immutable', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['execution_id']);

        $schema->createTable($table);
    }

    /**
     * Ajoute la colonne `recorded_at` si la table existait déjà sans elle (idempotent).
     *
     * Appelée aussi automatiquement avant lecture/écriture ({@see ensureRecordedAtColumnOnce}) pour les bases
     * créées avant l’introduction de la colonne, sans exécuter manuellement {@see createSchema} ou la commande Symfony.
     */
    public function ensureRecordedAtColumn(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist([$this->tableName])) {
            return;
        }

        $table = $schemaManager->introspectTable($this->tableName);
        if ($table->hasColumn('recorded_at')) {
            return;
        }

        $platform = $this->connection->getDatabasePlatform();
        $qTable = $this->connection->quoteIdentifier($this->tableName);
        $qCol = $this->connection->quoteIdentifier('recorded_at');

        if ($platform instanceof SQLitePlatform) {
            $this->connection->executeStatement(
                'ALTER TABLE '.$qTable.' ADD COLUMN '.$qCol.' DATETIME DEFAULT NULL'
            );

            return;
        }

        $currentSchema = $schemaManager->introspectSchema();
        if (!$currentSchema->hasTable($this->tableName)) {
            return;
        }

        $newSchema = clone $currentSchema;
        $newSchema->getTable($this->tableName)->addColumn('recorded_at', 'datetime_immutable', ['notnull' => false]);
        $schemaManager->migrateSchema($newSchema);
    }

    private function ensureRecordedAtColumnOnce(): void
    {
        if ($this->recordedAtColumnEnsured) {
            return;
        }

        $this->ensureRecordedAtColumn();
        $this->recordedAtColumnEnsured = true;
    }
}

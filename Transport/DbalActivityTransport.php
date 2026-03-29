<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

final class DbalActivityTransport implements ActivityTransportInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'durable_activity_outbox',
    ) {
    }

    public function enqueue(ActivityMessage $message): void
    {
        $meta = $message->metadata;
        $availableAt = null;
        if (isset($meta['retry_delay_seconds']) && (float) $meta['retry_delay_seconds'] > 0) {
            $availableAt = microtime(true) + (float) $meta['retry_delay_seconds'];
            unset($meta['retry_delay_seconds']);
        }

        $types = [
            'execution_id' => ParameterType::STRING,
            'activity_id' => ParameterType::STRING,
            'activity_name' => ParameterType::STRING,
            'payload' => ParameterType::STRING,
            'metadata' => ParameterType::STRING,
            'available_at' => null !== $availableAt ? Types::FLOAT : ParameterType::NULL,
        ];

        $this->connection->insert($this->tableName, [
            'execution_id' => $message->executionId,
            'activity_id' => $message->activityId,
            'activity_name' => $message->activityName,
            'payload' => json_encode($message->payload, \JSON_THROW_ON_ERROR),
            'metadata' => json_encode($meta, \JSON_THROW_ON_ERROR),
            'available_at' => $availableAt,
        ], $types);
    }

    public function dequeue(): ?ActivityMessage
    {
        return $this->connection->transactional(function () {
            $table = $this->connection->quoteIdentifier($this->tableName);
            $platform = $this->connection->getDatabasePlatform();
            $isMySQL = $platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

            $now = microtime(true);
            $sql = 'SELECT id, execution_id, activity_id, activity_name, payload, metadata FROM '.$table
                .' WHERE (available_at IS NULL OR available_at <= ?)'
                .' ORDER BY id ASC LIMIT 1';
            if ($isMySQL) {
                $sql .= ' FOR UPDATE SKIP LOCKED';
            }

            $row = $this->connection->fetchAssociative($sql, [$now], [Types::FLOAT]);
            if (false === $row) {
                return null;
            }

            $this->connection->delete($this->tableName, ['id' => $row['id']]);

            return new ActivityMessage(
                $row['execution_id'],
                $row['activity_id'],
                $row['activity_name'],
                json_decode($row['payload'], true, 512, \JSON_THROW_ON_ERROR),
                json_decode($row['metadata'], true, 512, \JSON_THROW_ON_ERROR) ?: [],
            );
        });
    }

    public function isEmpty(): bool
    {
        $table = $this->connection->quoteIdentifier($this->tableName);
        $now = microtime(true);
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM '.$table.' WHERE (available_at IS NULL OR available_at <= ?)',
            [$now],
            [Types::FLOAT],
        );

        return 0 === (int) $count;
    }

    public function removePendingFor(string $executionId, string $activityId): bool
    {
        $table = $this->connection->quoteIdentifier($this->tableName);

        return $this->connection->executeStatement(
            'DELETE FROM '.$table.' WHERE execution_id = ? AND activity_id = ?',
            [$executionId, $activityId],
        ) > 0;
    }

    public function createSchema(): void
    {
        $schema = $this->connection->createSchemaManager();
        $table = new \Doctrine\DBAL\Schema\Table($this->tableName);
        $table->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
        $table->addColumn('execution_id', Types::STRING, ['length' => 36]);
        $table->addColumn('activity_id', Types::STRING, ['length' => 36]);
        $table->addColumn('activity_name', Types::STRING, ['length' => 255]);
        $table->addColumn('payload', Types::TEXT);
        $table->addColumn('metadata', Types::TEXT);
        $table->addColumn('available_at', Types::FLOAT, ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['execution_id']);

        $schema->createTable($table);
    }
}

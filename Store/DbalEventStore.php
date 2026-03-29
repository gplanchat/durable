<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Gplanchat\Durable\Event\Event;

final class DbalEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'durable_events',
    ) {
    }

    public function append(Event $event): void
    {
        $row = EventSerializer::serialize($event);
        $this->connection->insert($this->tableName, [
            'execution_id' => $row['execution_id'],
            'event_type' => $row['event_type'],
            'payload' => json_encode($row['payload'], \JSON_THROW_ON_ERROR),
        ], [
            'payload' => ParameterType::STRING,
        ]);
    }

    /**
     * @return \Generator<int, Event>
     */
    public function readStream(string $executionId): iterable
    {
        $sql = 'SELECT execution_id, event_type, payload FROM '.$this->connection->quoteIdentifier($this->tableName)
            .' WHERE execution_id = ? ORDER BY id ASC';

        $result = $this->connection->executeQuery(
            $sql,
            [$executionId],
            [ParameterType::STRING],
        );

        foreach ($result->iterateAssociative() as $row) {
            yield EventSerializer::deserialize($row);
        }
    }

    public function createSchema(): void
    {
        $schema = $this->connection->createSchemaManager();
        $table = new \Doctrine\DBAL\Schema\Table($this->tableName);
        $table->addColumn('id', 'bigint', ['autoincrement' => true]);
        $table->addColumn('execution_id', 'string', ['length' => 36]);
        $table->addColumn('event_type', 'string', ['length' => 255]);
        $table->addColumn('payload', 'text');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['execution_id']);

        $schema->createTable($table);
    }
}

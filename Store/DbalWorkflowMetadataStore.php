<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalWorkflowMetadataStore implements WorkflowMetadataStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'durable_workflow_metadata',
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function save(string $executionId, string $workflowType, array $payload): void
    {
        $this->connection->insert($this->tableName, [
            'execution_id' => $executionId,
            'workflow_type' => $workflowType,
            'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
        ], [
            'payload' => ParameterType::STRING,
        ]);
    }

    /**
     * @return array{workflowType: string, payload: array<string, mixed>}|null
     */
    public function get(string $executionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT workflow_type, payload FROM '.$this->connection->quoteIdentifier($this->tableName)
            .' WHERE execution_id = ?',
            [$executionId],
        );

        if (false === $row) {
            return null;
        }

        $payload = \is_string($row['payload']) ? json_decode($row['payload'], true, 512, \JSON_THROW_ON_ERROR) : $row['payload'];

        return [
            'workflowType' => $row['workflow_type'],
            'payload' => \is_array($payload) ? $payload : [],
        ];
    }

    public function delete(string $executionId): void
    {
        $this->connection->delete($this->tableName, ['execution_id' => $executionId]);
    }

    public function createSchema(): void
    {
        $schema = $this->connection->createSchemaManager();
        $table = new \Doctrine\DBAL\Schema\Table($this->tableName);
        $table->addColumn('execution_id', 'string', ['length' => 36]);
        $table->addColumn('workflow_type', 'string', ['length' => 255]);
        $table->addColumn('payload', 'text');
        $table->setPrimaryKey(['execution_id']);

        $schema->createTable($table);
    }
}

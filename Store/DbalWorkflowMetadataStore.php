<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalWorkflowMetadataStore implements WorkflowMetadataStore
{
    private bool $completedColumnEnsured = false;

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
        $this->ensureCompletedColumnOnce();
        $this->connection->insert($this->tableName, [
            'execution_id' => $executionId,
            'workflow_type' => $workflowType,
            'payload' => json_encode($payload, \JSON_THROW_ON_ERROR),
            'completed' => 0,
        ], [
            'payload' => ParameterType::STRING,
            'completed' => ParameterType::INTEGER,
        ]);
    }

    public function markCompleted(string $executionId): void
    {
        $this->ensureCompletedColumnOnce();
        $this->connection->update(
            $this->tableName,
            ['completed' => 1],
            ['execution_id' => $executionId],
            ['completed' => ParameterType::INTEGER],
        );
    }

    /**
     * @return array{workflowType: string, payload: array<string, mixed>, completed?: bool}|null
     */
    public function get(string $executionId): ?array
    {
        $this->ensureCompletedColumnOnce();
        $row = $this->connection->fetchAssociative(
            'SELECT workflow_type, payload, completed FROM '.$this->connection->quoteIdentifier($this->tableName)
            .' WHERE execution_id = ?',
            [$executionId],
        );

        if (false === $row) {
            return null;
        }

        $payload = \is_string($row['payload']) ? json_decode($row['payload'], true, 512, \JSON_THROW_ON_ERROR) : $row['payload'];
        $completed = isset($row['completed']) ? (bool) (int) $row['completed'] : false;

        return [
            'workflowType' => $row['workflow_type'],
            'payload' => \is_array($payload) ? $payload : [],
            'completed' => $completed,
        ];
    }

    public function hasActiveWorkflowMetadata(string $executionId): bool
    {
        $m = $this->get($executionId);
        if (null === $m) {
            return false;
        }

        return !($m['completed'] ?? false);
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
        $table->addColumn('completed', 'boolean', ['notnull' => true, 'default' => false]);
        $table->setPrimaryKey(['execution_id']);

        $schema->createTable($table);
    }

    /**
     * Ajoute la colonne `completed` si la table existait déjà sans elle (idempotent).
     */
    public function ensureCompletedColumn(): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $currentSchema = $schemaManager->introspectSchema();
        if (!$currentSchema->hasTable($this->tableName)) {
            return;
        }

        if ($currentSchema->getTable($this->tableName)->hasColumn('completed')) {
            return;
        }

        $newSchema = clone $currentSchema;
        $newSchema->getTable($this->tableName)->addColumn('completed', 'boolean', ['notnull' => true, 'default' => false]);
        $schemaManager->migrateSchema($newSchema);
    }

    private function ensureCompletedColumnOnce(): void
    {
        if ($this->completedColumnEnsured) {
            return;
        }
        $this->ensureCompletedColumn();
        $this->completedColumnEnsured = true;
    }
}

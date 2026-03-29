<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Doctrine\DBAL\Connection;

/**
 * Persistance du lien parent ↔ enfant (workflows enfants async Messenger, multi-workers).
 */
final class DbalChildWorkflowParentLinkStore implements ChildWorkflowParentLinkStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $tableName = 'durable_child_workflow_parent_link',
    ) {
    }

    public function link(string $childExecutionId, string $parentExecutionId): void
    {
        $this->connection->delete($this->tableName, ['child_execution_id' => $childExecutionId]);
        $this->connection->insert($this->tableName, [
            'child_execution_id' => $childExecutionId,
            'parent_execution_id' => $parentExecutionId,
        ]);
    }

    public function getParentExecutionId(string $childExecutionId): ?string
    {
        $v = $this->connection->fetchOne(
            'SELECT parent_execution_id FROM '.$this->connection->quoteIdentifier($this->tableName)
            .' WHERE child_execution_id = ?',
            [$childExecutionId],
        );

        return \is_string($v) ? $v : null;
    }

    public function unlink(string $childExecutionId): void
    {
        $this->connection->delete($this->tableName, ['child_execution_id' => $childExecutionId]);
    }

    public function createSchema(): void
    {
        $schema = $this->connection->createSchemaManager();
        $table = new \Doctrine\DBAL\Schema\Table($this->tableName);
        $table->addColumn('child_execution_id', 'string', ['length' => 255]);
        $table->addColumn('parent_execution_id', 'string', ['length' => 255]);
        $table->setPrimaryKey(['child_execution_id']);

        $schema->createTable($table);
    }
}

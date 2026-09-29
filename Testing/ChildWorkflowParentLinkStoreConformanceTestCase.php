<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Conformance suite for {@see ChildWorkflowParentLinkStoreInterface} — DUR041.
 *
 * The contract says "order not guaranteed" for the children of a parent. The suite honours that by
 * sorting before comparing: freezing an order the port does not promise would fail a correct
 * adapter, which is the surest way to make a conformance suite unusable.
 *
 * @see DUR041
 */
abstract class ChildWorkflowParentLinkStoreConformanceTestCase extends TestCase
{
    abstract protected function createParentLinkStore(): ChildWorkflowParentLinkStoreInterface;

    public function testALinkedChildFindsItsParent(): void
    {
        $store = $this->createParentLinkStore();
        $store->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-1'));

        self::assertSame('parent-1', $store->getParentExecutionId(ExecutionId::fromString('child-1'))?->toString());
    }

    public function testAnUnknownChildHasNoParentRatherThanAnError(): void
    {
        $store = $this->createParentLinkStore();

        self::assertNull($store->getParentExecutionId(ExecutionId::fromString('child-nobody')));
        self::assertSame([], self::sorted($store->getChildExecutionIdsForParent(ExecutionId::fromString('parent-nobody'))));
    }

    public function testAParentFindsEveryChildItHasAndNoOther(): void
    {
        $store = $this->createParentLinkStore();
        $store->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-1'));
        $store->link(ExecutionId::fromString('child-2'), ExecutionId::fromString('parent-1'));
        $store->link(ExecutionId::fromString('child-3'), ExecutionId::fromString('parent-2'));

        self::assertSame(['child-1', 'child-2'], self::sorted($store->getChildExecutionIdsForParent(ExecutionId::fromString('parent-1'))));
        self::assertSame(['child-3'], self::sorted($store->getChildExecutionIdsForParent(ExecutionId::fromString('parent-2'))));
    }

    public function testRelinkingAChildMovesItRatherThanDuplicatingIt(): void
    {
        $store = $this->createParentLinkStore();
        $store->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-1'));

        $store->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-2'));

        self::assertSame('parent-2', $store->getParentExecutionId(ExecutionId::fromString('child-1'))?->toString());
        self::assertSame([], self::sorted($store->getChildExecutionIdsForParent(ExecutionId::fromString('parent-1'))));
        self::assertSame(['child-1'], self::sorted($store->getChildExecutionIdsForParent(ExecutionId::fromString('parent-2'))));
    }

    public function testUnlinkingRemovesOneChildAndLeavesItsSiblings(): void
    {
        $store = $this->createParentLinkStore();
        $store->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-1'));
        $store->link(ExecutionId::fromString('child-2'), ExecutionId::fromString('parent-1'));

        $store->unlink(ExecutionId::fromString('child-1'));

        self::assertNull($store->getParentExecutionId(ExecutionId::fromString('child-1')));
        self::assertSame(['child-2'], self::sorted($store->getChildExecutionIdsForParent(ExecutionId::fromString('parent-1'))));
    }

    public function testUnlinkingAnUnknownChildIsNotAnError(): void
    {
        $store = $this->createParentLinkStore();
        $store->unlink(ExecutionId::fromString('child-nobody'));

        self::assertNull($store->getParentExecutionId(ExecutionId::fromString('child-nobody')));
    }

    public function testLinkingTheSameChildTwiceIsIdempotent(): void
    {
        $store = $this->createParentLinkStore();
        $store->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-1'));
        $store->link(ExecutionId::fromString('child-1'), ExecutionId::fromString('parent-1'));

        self::assertSame(['child-1'], self::sorted($store->getChildExecutionIdsForParent(ExecutionId::fromString('parent-1'))));
    }

    /**
     * @param list<ExecutionId> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        $ids = array_map(static fn(ExecutionId $id): string => $id->toString(), $ids);
        sort($ids);

        return $ids;
    }
}

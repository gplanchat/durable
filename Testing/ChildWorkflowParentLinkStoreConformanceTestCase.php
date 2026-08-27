<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Suite de conformité de {@see ChildWorkflowParentLinkStoreInterface} — DUR041.
 *
 * Le contrat dit « ordre non garanti » pour les enfants d'un parent. La suite le respecte en triant
 * avant de comparer : figer un ordre que le port ne promet pas ferait échouer un adaptateur correct,
 * ce qui est la façon la plus sûre de rendre une suite de conformité inutilisable.
 *
 * @see DUR041
 */
abstract class ChildWorkflowParentLinkStoreConformanceTestCase extends TestCase
{
    abstract protected function createParentLinkStore(): ChildWorkflowParentLinkStoreInterface;

    public function testALinkedChildFindsItsParent(): void
    {
        $store = $this->createParentLinkStore();
        $store->link('child-1', 'parent-1');

        self::assertSame('parent-1', $store->getParentExecutionId('child-1'));
    }

    public function testAnUnknownChildHasNoParentRatherThanAnError(): void
    {
        $store = $this->createParentLinkStore();

        self::assertNull($store->getParentExecutionId('child-nobody'));
        self::assertSame([], $store->getChildExecutionIdsForParent('parent-nobody'));
    }

    public function testAParentFindsEveryChildItHasAndNoOther(): void
    {
        $store = $this->createParentLinkStore();
        $store->link('child-1', 'parent-1');
        $store->link('child-2', 'parent-1');
        $store->link('child-3', 'parent-2');

        self::assertSame(['child-1', 'child-2'], self::sorted($store->getChildExecutionIdsForParent('parent-1')));
        self::assertSame(['child-3'], self::sorted($store->getChildExecutionIdsForParent('parent-2')));
    }

    public function testRelinkingAChildMovesItRatherThanDuplicatingIt(): void
    {
        $store = $this->createParentLinkStore();
        $store->link('child-1', 'parent-1');

        $store->link('child-1', 'parent-2');

        self::assertSame('parent-2', $store->getParentExecutionId('child-1'));
        self::assertSame([], $store->getChildExecutionIdsForParent('parent-1'));
        self::assertSame(['child-1'], self::sorted($store->getChildExecutionIdsForParent('parent-2')));
    }

    public function testUnlinkingRemovesOneChildAndLeavesItsSiblings(): void
    {
        $store = $this->createParentLinkStore();
        $store->link('child-1', 'parent-1');
        $store->link('child-2', 'parent-1');

        $store->unlink('child-1');

        self::assertNull($store->getParentExecutionId('child-1'));
        self::assertSame(['child-2'], self::sorted($store->getChildExecutionIdsForParent('parent-1')));
    }

    public function testUnlinkingAnUnknownChildIsNotAnError(): void
    {
        $store = $this->createParentLinkStore();
        $store->unlink('child-nobody');

        self::assertNull($store->getParentExecutionId('child-nobody'));
    }

    public function testLinkingTheSameChildTwiceIsIdempotent(): void
    {
        $store = $this->createParentLinkStore();
        $store->link('child-1', 'parent-1');
        $store->link('child-1', 'parent-1');

        self::assertSame(['child-1'], self::sorted($store->getChildExecutionIdsForParent('parent-1')));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}

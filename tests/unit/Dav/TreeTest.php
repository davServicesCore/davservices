<?php

/*
 * This file is part of davServices.
 *
 * (c) Felix Böck <https://dav.services>
 *
 * Licensed under the Apache License, Version 2.0.
 * For the full copyright and license information, see the LICENSE file.
 */

declare(strict_types=1);

namespace DavServices\Tests\Unit\Dav;

use DavServices\Dav\ICollection;
use DavServices\Dav\Tree;
use DavServices\Exception\NotFound;
use DavServices\Uri\MalformedPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-TREE-04 (paths decoded, normalised and safe) and
 * R-TREE-05 (nodes cached for the length of a request).
 *
 * The cache is not a nicety. A `PROPFIND` with `Depth: 1` on a collection of
 * two hundred members asks for every one of them, and several plugins ask
 * again for the same node while they answer it. Without a cache the backend
 * sees each of those, and a report that should be one query becomes hundreds.
 *
 * What is cached must also be forgotten at the right moment: a node that was
 * deleted and is still handed out is worse than one that was never cached.
 */
#[CoversClass(Tree::class)]
final class TreeTest extends TestCase
{
    public function testTheEmptyPathIsTheRoot(): void
    {
        $root = new MemoryCollection('');

        self::assertSame($root, (new Tree($root))->node(''));
    }

    public function testFindsAMemberOfTheRoot(): void
    {
        $file = new MemoryFile('notes.txt');
        $tree = new Tree((new MemoryCollection(''))->add($file));

        self::assertSame($file, $tree->node('notes.txt'));
    }

    public function testWalksDownToANodeSeveralLevelsIn(): void
    {
        $file = new MemoryFile('work.ics');
        $tree = new Tree($this->calendarsOf($file));

        self::assertSame($file, $tree->node('calendars/alice/work.ics'));
    }

    /**
     * The path arrives as the client wrote it and is brought into the form the
     * tree works with before anything is looked up (R-TREE-04).
     */
    #[DataProvider('equivalentPaths')]
    public function testNormalisesThePathBeforeLookingAnythingUp(string $path): void
    {
        $tree = new Tree($this->calendarsOf(new MemoryFile('work.ics')));

        self::assertSame('work.ics', $tree->node($path)->name());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentPaths(): iterable
    {
        yield 'as it is' => ['calendars/alice/work.ics'];
        yield 'with a leading slash' => ['/calendars/alice/work.ics'];
        yield 'with doubled slashes' => ['//calendars//alice/work.ics'];
        yield 'percent-encoded' => ['/calendars/alice/work%2Eics'];
        yield 'with a dot segment' => ['/calendars/./alice/work.ics'];
        yield 'with a segment climbed back out of' => ['/calendars/bob/../alice/work.ics'];
    }

    /**
     * The refusal comes from the path layer and is passed on as it is: a
     * target that climbs above the root is not a missing node, and answering
     * `404` would tell a prober that the path shape is at least acceptable.
     */
    public function testPassesOnTheRefusalOfATargetThatClimbsAboveTheRoot(): void
    {
        $this->expectException(MalformedPath::class);

        (new Tree(new MemoryCollection('')))->node('../etc/passwd');
    }

    public function testAPathThatLeadsNowhereIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        (new Tree($this->calendarsOf(new MemoryFile('work.ics'))))->node('calendars/alice/holiday.ics');
    }

    /**
     * A file has no members, so a path that goes on past one leads nowhere —
     * rather than to an error about the wrong kind of node, which would tell a
     * client something about the tree that it has no business knowing.
     */
    public function testAPathThroughAFileIsNotFound(): void
    {
        $this->expectException(NotFound::class);

        (new Tree($this->calendarsOf(new MemoryFile('work.ics'))))->node('calendars/alice/work.ics/deeper');
    }

    public function testSaysWhetherAPathLeadsAnywhere(): void
    {
        $tree = new Tree($this->calendarsOf(new MemoryFile('work.ics')));

        self::assertTrue($tree->exists('calendars/alice/work.ics'));
        self::assertTrue($tree->exists(''));
        self::assertFalse($tree->exists('calendars/alice/holiday.ics'));
        self::assertFalse($tree->exists('nothing/here'));
    }

    /**
     * A malformed path leads nowhere either. Asking whether something exists
     * is a question, not an operation, and it is answered rather than refused.
     */
    public function testAMalformedPathLeadsNowhere(): void
    {
        self::assertFalse((new Tree(new MemoryCollection('')))->exists('../etc/passwd'));
    }

    /**
     * R-TREE-05: within one request the same node is handed out again rather
     * than fetched again.
     */
    public function testAsksTheBackendOnceForTheSameNode(): void
    {
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics'));
        $tree = new Tree((new MemoryCollection(''))->add($alice));

        $first = $tree->node('alice/work.ics');
        $second = $tree->node('alice/work.ics');

        self::assertSame($first, $second);
        self::assertSame(1, $alice->lookups);
    }

    /**
     * Everything on the way down is cached too, which is what makes the second
     * of two neighbouring lookups cheap — a `PROPFIND` asks for a collection
     * and then for every one of its members.
     */
    public function testCachesTheCollectionsItPassedThrough(): void
    {
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics'));
        $home = (new MemoryCollection('home'))->add($alice);
        $tree = new Tree((new MemoryCollection(''))->add($home));

        $tree->node('home/alice/work.ics');
        $tree->node('home/alice');

        self::assertSame(1, $home->lookups);
    }

    public function testForgetsANodeSoThatItIsFetchedAgain(): void
    {
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics'));
        $tree = new Tree((new MemoryCollection(''))->add($alice));

        $tree->node('alice/work.ics');
        $tree->forget('alice/work.ics');
        $tree->node('alice/work.ics');

        self::assertSame(2, $alice->lookups);
    }

    /**
     * Forgetting a collection forgets what was below it. A `DELETE` takes a
     * whole subtree with it, and a member handed out afterwards would be a
     * node that no longer exists.
     */
    public function testForgettingACollectionForgetsEverythingBelowIt(): void
    {
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics'));
        $tree = new Tree((new MemoryCollection(''))->add($alice));

        $tree->node('alice/work.ics');
        $tree->forget('alice');
        $tree->node('alice/work.ics');

        self::assertSame(2, $alice->lookups);
    }

    /**
     * A name that merely begins with the same letters is a different node.
     * `alice2` is not below `alice`, and forgetting one must not forget the
     * other — the sort of bug a prefix comparison brings and a test catches.
     */
    public function testForgettingOneCollectionLeavesItsNamesakeAlone(): void
    {
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics'));
        $alice2 = (new MemoryCollection('alice2'))->add(new MemoryFile('work.ics'));
        $tree = new Tree((new MemoryCollection(''))->add($alice)->add($alice2));

        $tree->node('alice/work.ics');
        $tree->node('alice2/work.ics');
        $tree->forget('alice');
        $tree->node('alice2/work.ics');

        self::assertSame(1, $alice2->lookups);
    }

    public function testForgettingThePathOfANodeNobodyAskedForChangesNothing(): void
    {
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics'));
        $tree = new Tree((new MemoryCollection(''))->add($alice));

        $tree->forget('alice/work.ics');

        self::assertSame('work.ics', $tree->node('alice/work.ics')->name());
    }

    /**
     * Forgetting the root empties the cache: the tree is then as it was found.
     */
    public function testForgettingTheRootEmptiesTheWholeCache(): void
    {
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics'));
        $tree = new Tree((new MemoryCollection(''))->add($alice));

        $tree->node('alice/work.ics');
        $tree->forget('');
        $tree->node('alice/work.ics');

        self::assertSame(2, $alice->lookups);
    }

    public function testHandsOverTheRootItWasBuiltWith(): void
    {
        $root = new MemoryCollection('');

        self::assertSame($root, (new Tree($root))->root());
    }

    private function calendarsOf(MemoryFile $file): ICollection
    {
        $alice = (new MemoryCollection('alice'))->add($file);

        return (new MemoryCollection(''))->add((new MemoryCollection('calendars'))->add($alice));
    }
}

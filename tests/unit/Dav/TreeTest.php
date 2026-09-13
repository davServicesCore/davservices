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
use DavServices\Exception\Conflict;
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

    /**
     * The rule every method that creates something needs: the collection it
     * would go into has to be there already (RFC 4918 §9.3.1 and §9.7.1).
     */
    public function testHandsOverTheCollectionSomethingWouldGoInto(): void
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');
        $root->add($calendars);

        self::assertSame($calendars, (new Tree($root))->collectionAt('calendars'));
    }

    /**
     * A `409`, not a `404`: the client asked to create something, and what is
     * wrong is the place rather than the request.
     */
    public function testRefusesToFindACollectionThatIsNotThere(): void
    {
        $this->expectException(Conflict::class);

        (new Tree(new MemoryCollection('')))->collectionAt('nowhere');
    }

    public function testRefusesAFileAsSomethingToPutAMemberIn(): void
    {
        $root = new MemoryCollection('');
        $root->add(new MemoryFile('notes.txt'));

        $this->expectException(Conflict::class);

        (new Tree($root))->collectionAt('notes.txt');
    }

    public function testCopiesAFileAndLeavesTheOriginalWhereItIs(): void
    {
        $root = new MemoryCollection('');
        $root->add(new MemoryFile('work.ics', 'a meeting'));

        $failures = (new Tree($root))->copy('work.ics', 'copy.ics');

        self::assertSame([], $failures);
        self::assertSame('a meeting', $this->contentOf($root, 'copy.ics'));
        self::assertTrue($root->hasChild('work.ics'), 'A copy took the original with it.');
    }

    /**
     * RFC 4918 §9.8.3: a copy of a collection goes as deep as the `Depth` says,
     * and `infinity` is the usual case.
     */
    public function testCopiesACollectionWithEverythingBelowIt(): void
    {
        $root = new MemoryCollection('');
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics', 'a meeting'));
        $root->add((new MemoryCollection('calendars'))->add($alice));

        (new Tree($root))->copy('calendars', 'archive');

        $archive = $this->collectionIn($root, 'archive');

        self::assertSame('a meeting', $this->contentOf($this->collectionIn($archive, 'alice'), 'work.ics'));
    }

    /**
     * `Depth: 0` copies the collection and not its members: an empty one of
     * the same name, which is what a client that asked for it expects.
     */
    public function testCopiesACollectionWithoutItsMembersWhereItIsTold(): void
    {
        $root = new MemoryCollection('');
        $root->add((new MemoryCollection('calendars'))->add(new MemoryFile('work.ics', 'a meeting')));

        (new Tree($root))->copy('calendars', 'archive', false);

        self::assertSame([], $this->collectionIn($root, 'archive')->children());
    }

    public function testMovesAFileAndLeavesNothingBehind(): void
    {
        $root = new MemoryCollection('');
        $root->add(new MemoryFile('work.ics', 'a meeting'));

        (new Tree($root))->move('work.ics', 'archive.ics');

        self::assertSame('a meeting', $this->contentOf($root, 'archive.ics'));
        self::assertFalse($root->hasChild('work.ics'), 'The original stayed where it was.');
    }

    public function testMovesACollectionWithEverythingBelowIt(): void
    {
        $root = new MemoryCollection('');
        $root->add((new MemoryCollection('calendars'))->add(new MemoryFile('work.ics', 'a meeting')));

        (new Tree($root))->move('calendars', 'archive');

        self::assertFalse($root->hasChild('calendars'));
        self::assertSame('a meeting', $this->contentOf($this->collectionIn($root, 'archive'), 'work.ics'));
    }

    /**
     * R-TREE-06: a backend that can do it in one operation is asked first. A
     * server that walked a million-row calendar node by node when the database
     * could have renamed it is the difference between a moment and an hour.
     */
    public function testAsksTheBackendToDoItItself(): void
    {
        $root = new MemoryCollection('');
        $archive = new MemoryTransferCollection('archive');

        $root->add(new MemoryFile('work.ics', 'a meeting'));
        $root->add($archive);

        (new Tree($root))->copy('work.ics', 'archive/work.ics');

        self::assertSame(['work.ics'], $archive->copiedIn);
        self::assertTrue($archive->hasChild('work.ics'));
    }

    /**
     * And a backend that says no is not failing: it is handing the work back,
     * and the server does it node by node.
     */
    public function testDoesItItselfWhereTheBackendHandsItBack(): void
    {
        $root = new MemoryCollection('');
        $archive = (new MemoryTransferCollection('archive'))->handsItBack();

        $root->add(new MemoryFile('work.ics', 'a meeting'));
        $root->add($archive);

        (new Tree($root))->copy('work.ics', 'archive/work.ics');

        self::assertSame(['work.ics'], $archive->copiedIn);
        self::assertSame('a meeting', $this->contentOf($archive, 'work.ics'));
    }

    public function testAsksTheBackendToMoveItItself(): void
    {
        $root = new MemoryCollection('');
        $archive = new MemoryTransferCollection('archive');

        $root->add(new MemoryFile('work.ics', 'a meeting'));
        $root->add($archive);

        (new Tree($root))->move('work.ics', 'archive/work.ics');

        self::assertSame(['work.ics'], $archive->movedIn);
        self::assertFalse($root->hasChild('work.ics'));
    }

    public function testMovesItItselfWhereTheBackendHandsItBack(): void
    {
        $root = new MemoryCollection('');
        $archive = (new MemoryTransferCollection('archive'))->handsItBack();

        $root->add(new MemoryFile('work.ics', 'a meeting'));
        $root->add($archive);

        (new Tree($root))->move('work.ics', 'archive/work.ics');

        self::assertSame('a meeting', $this->contentOf($archive, 'work.ics'));
        self::assertFalse($root->hasChild('work.ics'));
    }

    /**
     * RFC 4918 §9.8.5: a member that could not be copied is named, and the
     * ones that could are not. The path named is the one the client knows —
     * the member of the source it asked to have copied.
     */
    public function testNamesTheMemberThatCouldNotBeCopied(): void
    {
        $root = new MemoryCollection('');
        $calendars = (new MemoryCollection('calendars'))->add((new MemoryFile('work.ics'))->refuseReading());

        $root->add($calendars);

        $failures = (new Tree($root))->copy('calendars', 'archive');

        self::assertSame(['calendars/work.ics'], array_keys($failures));
    }

    /**
     * Every member that would not copy is named, not the first of them — and a
     * member that copies after one that did not does not wipe the record of
     * it. Whether the answer is right must not depend on the order the backend
     * lists its members in.
     */
    public function testNamesEveryMemberThatWouldNotCopy(): void
    {
        $root = new MemoryCollection('');
        $calendars = (new MemoryCollection('calendars'))
            ->add((new MemoryFile('work.ics'))->refuseReading())
            ->add(new MemoryFile('home.ics', 'at home'))
            ->add((new MemoryFile('leave.ics'))->refuseReading());

        $root->add($calendars);

        $failures = (new Tree($root))->copy('calendars', 'archive');

        self::assertSame(['calendars/work.ics', 'calendars/leave.ics'], array_keys($failures));
    }

    public function testNamesEveryMemberThatWouldNotMove(): void
    {
        $root = new MemoryCollection('');
        $calendars = (new MemoryCollection('calendars'))
            ->add((new MemoryFile('work.ics'))->refuseReading())
            ->add((new MemoryFile('leave.ics'))->refuseReading());

        $root->add($calendars);

        $failures = (new Tree($root))->move('calendars', 'archive');

        self::assertSame(['calendars/work.ics', 'calendars/leave.ics'], array_keys($failures));
        self::assertTrue($root->hasChild('calendars'), 'It was removed although it would not copy.');
    }

    /**
     * R-TREE-05: after a move, what the tree kept about **both** ends is
     * wrong. The source is gone, and handing it out again from the cache would
     * show a client a resource that is not there.
     */
    public function testForgetsBothEndsOfAMove(): void
    {
        $root = new MemoryCollection('');
        $archive = new MemoryCollection('archive');

        $root->add(new MemoryFile('work.ics', 'a meeting'));
        $root->add($archive);

        $tree = new Tree($root);

        $tree->node('work.ics');
        $tree->node('archive');
        $tree->move('work.ics', 'archive/work.ics');

        self::assertFalse($tree->exists('work.ics'), 'The source was handed out from the cache after it had moved.');

        $root->lookups = 0;
        $tree->node('archive');

        self::assertSame(1, $root->lookups, 'The destination was handed out from the cache after a move.');
    }

    /**
     * And the same where the backend moved it itself: the tree cached what it
     * saw, whoever did the moving.
     */
    public function testForgetsBothEndsWhereTheBackendMovedItItself(): void
    {
        $root = new MemoryCollection('');
        $archive = new MemoryTransferCollection('archive');

        $root->add(new MemoryFile('work.ics', 'a meeting'));
        $root->add($archive);

        $tree = new Tree($root);

        $tree->node('work.ics');
        $tree->node('archive');
        $tree->move('work.ics', 'archive/work.ics');

        self::assertFalse($tree->exists('work.ics'), 'The source was handed out from the cache after it had moved.');

        $root->lookups = 0;
        $tree->node('archive');

        self::assertSame(1, $root->lookups, 'The destination was handed out from the cache after a move.');
    }

    public function testRefusesToCopyToAPlaceThatIsNotThere(): void
    {
        $root = new MemoryCollection('');
        $root->add(new MemoryFile('work.ics', 'a meeting'));

        $this->expectException(Conflict::class);

        (new Tree($root))->copy('work.ics', 'nowhere/work.ics');
    }

    public function testRefusesToCopyWhatIsNotThere(): void
    {
        $this->expectException(NotFound::class);

        (new Tree(new MemoryCollection('')))->copy('nowhere.ics', 'work.ics');
    }

    /**
     * R-TREE-05: what the tree kept about the destination is wrong the moment
     * something is copied over it.
     */
    public function testForgetsWhereTheCopyWent(): void
    {
        $root = new MemoryCollection('');
        $archive = new MemoryCollection('archive');

        $root->add(new MemoryFile('work.ics', 'a meeting'));
        $root->add($archive);

        $tree = new Tree($root);

        $tree->node('archive');
        $tree->copy('work.ics', 'archive/work.ics');

        $root->lookups = 0;
        $tree->node('archive');

        self::assertSame(1, $root->lookups, 'The destination was handed out from the cache after a copy.');
    }

    /**
     * A node that is neither a file nor a collection is something this library
     * has no way of making a second one of, and says so rather than making an
     * empty something.
     */
    public function testRefusesToCopyANodeThatIsNeitherFileNorCollection(): void
    {
        $root = new MemoryCollection('');
        $root->add(new PlainNode('thing'));

        $failures = (new Tree($root))->copy('thing', 'copy');

        self::assertSame(['thing'], array_keys($failures));
        self::assertFalse($root->hasChild('copy'));
    }

    /**
     * A collection that will not say what it holds cannot be copied whole, and
     * nothing below it may be taken to have been copied.
     */
    public function testNamesACollectionThatWouldNotBeListed(): void
    {
        $root = new MemoryCollection('');
        $root->add((new MemoryCollection('calendars'))->refuseListing());

        $failures = (new Tree($root))->copy('calendars', 'archive');

        self::assertSame(['calendars'], array_keys($failures));
    }

    /**
     * A backend that answers a request for a collection with something else has
     * misunderstood its own contract. Carrying on would copy the members into
     * whatever it did make.
     */
    public function testNamesACollectionTheBackendDidNotMake(): void
    {
        $root = new MemoryCollection('');
        $root->add((new MemoryCollection('calendars'))->add(new MemoryFile('work.ics')));
        $root->makeSomethingElse();

        $failures = (new Tree($root))->copy('calendars', 'archive');

        self::assertSame(['calendars'], array_keys($failures));
    }

    private function collectionIn(MemoryCollection $parent, string $name): MemoryCollection
    {
        $child = $parent->child($name);

        if (!$child instanceof MemoryCollection) {
            self::fail(sprintf('"%s" is no collection.', $name));
        }

        return $child;
    }

    private function contentOf(MemoryCollection $parent, string $name): string
    {
        $child = $parent->child($name);

        if (!$child instanceof MemoryFile) {
            self::fail(sprintf('"%s" is no file.', $name));
        }

        return $child->get();
    }

    private function calendarsOf(MemoryFile $file): ICollection
    {
        $alice = (new MemoryCollection('alice'))->add($file);

        return (new MemoryCollection(''))->add((new MemoryCollection('calendars'))->add($alice));
    }
}

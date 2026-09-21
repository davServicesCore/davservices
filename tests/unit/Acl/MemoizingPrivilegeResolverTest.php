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

namespace DavServices\Tests\Unit\Acl;

use DavServices\Acl\IPrivilegeResolver;
use DavServices\Acl\MemoizingPrivilegeResolver;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The contract again, this time through the wrapper — plus what only a
 * wrapper can get wrong.
 *
 * R-PRIV-02 asks that resolution be memoised within a request. Rather than
 * ask every implementation to keep its own cache and get it right, this one
 * does it once and wraps whatever an application wrote.
 *
 * **The dangerous mistake a cache like this can make is remembering too
 * much.** An answer belongs to a principal *and* a path; a cache keyed by
 * path alone would hand Alice's privileges to Bob on the second request of
 * the same process, and everything above it would look right while doing it.
 * That is the test this file exists for.
 *
 * The other one is subtler: **a batch must fill the cache for every path it
 * asked about**, or the next single question repeats work the batch already
 * did — which is the N+1 the batch was there to prevent, arriving one step
 * later.
 */
#[CoversClass(MemoizingPrivilegeResolver::class)]
final class MemoizingPrivilegeResolverTest extends PrivilegeResolverContract
{
    private ?ArrayPrivilegeResolver $inner = null;

    private ?MemoizingPrivilegeResolver $resolver = null;

    protected function setUp(): void
    {
        $members = [];

        for ($member = 0; $member < 10; $member++) {
            $members[] = sprintf('calendars/member-%d', $member);
        }

        $this->inner = ArrayPrivilegeResolver::asTheContractExpects($members);
        $this->resolver = new MemoizingPrivilegeResolver($this->inner);
    }

    /**
     * R-PRIV-02: the same question twice costs one answer. A `PROPFIND` asks
     * about a collection and then about each of its members, and the
     * collection comes up again in the second half.
     */
    public function testAsksTheOneBehindItOnlyOnceForTheSamePath(): void
    {
        $resolver = $this->memoizing();

        $resolver->forPath(self::ALICE, 'calendars/work');
        $resolver->forPath(self::ALICE, 'calendars/work');

        self::assertSame(1, $this->innerResolver()->lookups);
    }

    /**
     * **And the answer is the same one.** A cache that remembered the
     * question but not the answer would be a slower way of asking twice.
     */
    public function testTheRememberedAnswerIsTheAnswer(): void
    {
        $resolver = $this->memoizing();

        self::assertSame(
            $resolver->forPath(self::ALICE, 'calendars/work')->flattened(),
            $resolver->forPath(self::ALICE, 'calendars/work')->flattened(),
        );
    }

    /**
     * **The mistake this test exists for.** An answer belongs to a principal
     * and a path together. A cache keyed by path alone would hand Alice's
     * privileges to Bob, and every check above it would happily let him
     * write.
     */
    public function testRemembersWhoTheAnswerWasFor(): void
    {
        $resolver = $this->memoizing();

        $resolver->forPath(self::ALICE, 'calendars/work');

        self::assertFalse($resolver->forPath(self::BOB, 'calendars/work')->has('{DAV:}write'));
        self::assertTrue($resolver->forPath(self::BOB, 'calendars/work')->has('{DAV:}read'));
    }

    /**
     * And not being signed in is its own answer, not the last one somebody
     * else got.
     */
    public function testRemembersNobodyApartFromSomebody(): void
    {
        $resolver = $this->memoizing();

        $resolver->forPath(self::ALICE, 'calendars/work');

        self::assertTrue($resolver->forPath(null, 'calendars/work')->isEmpty());
    }

    /**
     * **A batch fills the cache for everything it asked about.** Otherwise
     * the single questions that follow repeat the work the batch just did,
     * and the N+1 arrives one step later than it would have.
     */
    public function testWhatABatchLearnedIsNotAskedAgain(): void
    {
        $resolver = $this->memoizing();

        $resolver->forPaths(self::ALICE, ['calendars/work', 'calendars/member-0']);
        $resolver->forPath(self::ALICE, 'calendars/member-0');

        self::assertSame(1, $this->innerResolver()->lookups);
    }

    /**
     * And a batch asks only about what it does not know yet: half of a
     * listing already answered is half a question.
     */
    public function testABatchAsksOnlyAboutWhatItDoesNotKnowYet(): void
    {
        $resolver = $this->memoizing();

        $resolver->forPath(self::ALICE, 'calendars/work');
        $resolver->forPaths(self::ALICE, ['calendars/work']);

        self::assertSame(1, $this->innerResolver()->lookups);
    }

    /**
     * But a batch that knows nothing yet still asks **once**, not once per
     * path: the wrapper must not turn the one query back into many.
     */
    public function testABatchOfUnknownPathsIsStillOneQuestion(): void
    {
        $resolver = $this->memoizing();

        $resolver->forPaths(self::ALICE, ['calendars/member-0', 'calendars/member-1', 'calendars/member-2']);

        self::assertSame(1, $this->innerResolver()->lookups);
    }

    /**
     * A batch of nothing asks nothing. A `PROPFIND` on an empty collection
     * should not cost a query.
     */
    public function testABatchOfNothingAsksNothing(): void
    {
        $this->memoizing()->forPaths(self::ALICE, []);

        self::assertSame(0, $this->innerResolver()->lookups);
    }

    /**
     * The other direction is passed straight through: `DAV:acl` is asked
     * once per request, and remembering it would mean keeping a second cache
     * for a question nobody repeats.
     */
    public function testAsksTheOneBehindItWhoHoldsSomething(): void
    {
        $holders = $this->memoizing()->principalsForPath('calendars/work');

        self::assertSame([self::ALICE, self::BOB], array_keys($holders));
    }

    protected function resolver(): IPrivilegeResolver
    {
        return $this->memoizing();
    }

    /**
     * @param callable(IPrivilegeResolver): mixed $work
     */
    protected function askings(callable $work): int
    {
        $inner = $this->innerResolver();
        $before = $inner->lookups;

        $work($this->memoizing());

        return $inner->lookups - $before;
    }

    private function memoizing(): MemoizingPrivilegeResolver
    {
        return $this->resolver ?? self::fail('The test has no resolver.');
    }

    private function innerResolver(): ArrayPrivilegeResolver
    {
        return $this->inner ?? self::fail('The test has no resolver.');
    }
}

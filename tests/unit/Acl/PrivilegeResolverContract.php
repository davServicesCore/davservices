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
use DavServices\Acl\PrivilegeSet;
use PHPUnit\Framework\TestCase;

/**
 * What every privilege resolver has to do (R-PRIV-01 … R-PRIV-06, R-BE-04).
 *
 * **This is the one seam through which an application's own knowledge reaches
 * the protocol**, so it is also the one where a mistake is least visible from
 * the outside: a resolver that answered a little too generously would hand
 * out somebody else's calendar, and everything above it would look right.
 * Written once and run against every implementation.
 *
 * Four of these tests are about answers, and one is about **speed**, which is
 * unusual in a contract and deliberate. R-PRIV-01 says `forPaths()` may be a
 * loop over `forPath()` inside — and that such an implementation has to be
 * caught here, because it satisfies the interface while defeating its
 * purpose. A `PROPFIND` of two hundred members would become two hundred
 * questions to a database, and no amount of care further up could undo it.
 *
 * Counting is the implementation's to do, since only it knows what its own
 * storage is: {@see self::askings()} is handed a piece of work and says how
 * often the thing behind the resolver was consulted while it ran.
 */
abstract class PrivilegeResolverContract extends TestCase
{
    protected const ALICE = '/principals/alice';

    protected const BOB = '/principals/bob';

    public function testSaysWhatAPrincipalHoldsOnAPath(): void
    {
        $set = $this->resolver()->forPath(self::ALICE, 'calendars/work');

        self::assertTrue($set->has('{DAV:}read'));
        self::assertTrue($set->has('{DAV:}write-content'));
    }

    /**
     * R-PRIV-03: **nobody in particular holds nothing.** Not a guess, not an
     * error — an empty set, which every check reads as a refusal.
     */
    public function testNobodyInParticularHoldsNothing(): void
    {
        self::assertTrue($this->resolver()->forPath(null, 'calendars/work')->isEmpty());
    }

    public function testAPrincipalHoldsNothingWhereNothingWasGranted(): void
    {
        self::assertTrue($this->resolver()->forPath(self::BOB, 'calendars/private')->isEmpty());
    }

    /**
     * R-PRIV-02: the resolution has no side effects, so asking twice gives
     * the same answer. A resolver that consumed something as it answered
     * would make a second `PROPFIND` of the same collection say something
     * different.
     */
    public function testAskingTwiceGivesTheSameAnswer(): void
    {
        $resolver = $this->resolver();

        self::assertSame(
            $resolver->forPath(self::ALICE, 'calendars/work')->flattened(),
            $resolver->forPath(self::ALICE, 'calendars/work')->flattened(),
        );
    }

    /**
     * **Every path asked about appears in the answer**, which the interface
     * spells out: a caller that had to tell "no access" from "no answer"
     * would have to ask again, and asking again is the thing the batch
     * exists to prevent.
     */
    public function testAnswersAboutEveryPathItWasAskedAbout(): void
    {
        $paths = ['calendars/work', 'calendars/private', 'calendars/holidays'];

        $answers = $this->resolver()->forPaths(self::ALICE, $paths);

        self::assertSame($paths, array_keys($answers));
    }

    /**
     * R-PRIV-04: **a path nobody has heard of gets an empty set, not an
     * exception.** One mistyped member of a `Depth: 1` listing must not cost
     * the whole answer.
     */
    public function testAPathNobodyHasHeardOfGetsAnEmptySet(): void
    {
        $answers = $this->resolver()->forPaths(self::ALICE, ['calendars/work', 'nowhere/at/all']);

        self::assertTrue(($answers['nowhere/at/all'] ?? PrivilegeSet::nothing())->isEmpty());
        self::assertFalse(($answers['calendars/work'] ?? PrivilegeSet::nothing())->isEmpty());
    }

    public function testTheBatchSaysWhatTheSingleQuestionSays(): void
    {
        $resolver = $this->resolver();
        $answers = $resolver->forPaths(self::ALICE, ['calendars/work']);

        self::assertSame(
            $resolver->forPath(self::ALICE, 'calendars/work')->flattened(),
            ($answers['calendars/work'] ?? PrivilegeSet::nothing())->flattened(),
        );
    }

    /**
     * **R-PRIV-01, and the reason this contract counts.** An implementation
     * that loops over `forPath()` satisfies every other test here and still
     * turns a `PROPFIND` of two hundred members into two hundred questions.
     * Asking about ten paths must cost what asking about one costs.
     */
    public function testAsksForAllOfThemAtOnce(): void
    {
        $many = [];

        for ($member = 0; $member < 10; $member++) {
            $many[] = sprintf('calendars/member-%d', $member);
        }

        $forOne = $this->askings(fn (IPrivilegeResolver $resolver) => $resolver->forPaths(self::ALICE, ['calendars/work']));
        $forTen = $this->askings(fn (IPrivilegeResolver $resolver) => $resolver->forPaths(self::ALICE, $many));

        self::assertSame(
            $forOne,
            $forTen,
            'forPaths() asks about all of them at once rather than one at a time (R-PRIV-01).',
        );
    }

    /**
     * The other direction, which `DAV:acl` is written from: who may do
     * anything at all here.
     */
    public function testSaysWhichPrincipalsHoldSomethingOnAPath(): void
    {
        $holders = $this->resolver()->principalsForPath('calendars/work');

        self::assertSame([self::ALICE, self::BOB], array_keys($holders));
        self::assertTrue(($holders[self::BOB] ?? PrivilegeSet::nothing())->has('{DAV:}read'));
    }

    /**
     * And a path nobody holds anything on is answered with nobody — again
     * without an exception, because a resolver does not know what exists.
     */
    public function testSaysThatNobodyHoldsAnythingWhereNobodyDoes(): void
    {
        self::assertSame([], $this->resolver()->principalsForPath('nowhere/at/all'));
    }

    /**
     * The resolver under test, holding: Alice with `DAV:read` and
     * `DAV:write` on `calendars/work` and on every `calendars/member-*`;
     * Bob with `DAV:read` on `calendars/work` and nothing on
     * `calendars/private`.
     */
    abstract protected function resolver(): IPrivilegeResolver;

    /**
     * How often the thing behind the resolver was consulted while the work
     * ran.
     *
     * Only the implementation knows what its own storage is, so only it can
     * count. A resolver that keeps its answers in an array counts array
     * lookups; one in front of a database counts queries.
     *
     * @param callable(IPrivilegeResolver): mixed $work
     */
    abstract protected function askings(callable $work): int;
}

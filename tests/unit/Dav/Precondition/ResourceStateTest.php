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

namespace DavServices\Tests\Unit\Dav\Precondition;

use DavServices\Dav\Precondition\ResourceState;
use DavServices\Http\ETag;
use DavServices\Http\IfCondition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 4918 §10.4, for R-HTTP-07 and R-LOCK-04.
 *
 * What a client could have known about one resource: which lock tokens are
 * held on it, and what its entity tag is. Those are the two things an `If`
 * header asks about, and this is what one condition is held against.
 *
 * **A condition about a tag is compared weakly.** RFC 9110 §8.8.3.3 names a
 * comparison for each HTTP header, and WebDAV's `If` is in no such table — so
 * the question it asks decides it. `If` asks "is this still the state I saw",
 * which is what a weak tag answers at the granularity a server has; a byte
 * range asks whether two responses may be spliced, which it cannot answer.
 * That is why the file backend marks its own tags weak, and why comparing
 * them strongly here would make every conditional write against such a server
 * impossible.
 *
 * **A resource with no entity tag satisfies no condition about one.** Not
 * every backend has a tag for every node, and a comparison against nothing is
 * a comparison that failed — never one that passed.
 *
 * `Not` turns each answer round, and it is the whole reason the negation is
 * read here rather than by the caller: `Not <token>` on a resource that does
 * not hold that token is **true**, and a client sends it to say "only if
 * nobody else has taken this".
 */
#[CoversClass(ResourceState::class)]
final class ResourceStateTest extends TestCase
{
    private const TOKEN = 'opaquelocktoken:held';

    public function testATokenItHoldsSatisfiesAConditionAboutIt(): void
    {
        self::assertTrue($this->state()->satisfies(IfCondition::onStateToken(self::TOKEN, false)));
    }

    public function testATokenItDoesNotHoldSatisfiesNothing(): void
    {
        self::assertFalse($this->state()->satisfies(IfCondition::onStateToken('opaquelocktoken:other', false)));
    }

    /**
     * A resource may be held by several shared locks at once, and a client
     * naming any one of them has named one that is held.
     */
    public function testAnyOfTheTokensItHoldsWillDo(): void
    {
        $state = new ResourceState(['opaquelocktoken:one', 'opaquelocktoken:other'], null);

        self::assertTrue($state->satisfies(IfCondition::onStateToken('opaquelocktoken:other', false)));
    }

    /**
     * `Not <token>`: true where the resource does **not** hold it. A client
     * sends this to say "only if nobody else has taken this", and reading the
     * negation the wrong way round would let exactly the write through that
     * it was sent to prevent.
     */
    public function testANegatedTokenIsSatisfiedByNotHoldingIt(): void
    {
        $state = $this->state();

        self::assertTrue($state->satisfies(IfCondition::onStateToken('opaquelocktoken:other', true)));
        self::assertFalse($state->satisfies(IfCondition::onStateToken(self::TOKEN, true)));
    }

    public function testAMatchingEntityTagSatisfiesAConditionAboutIt(): void
    {
        self::assertTrue($this->state()->satisfies($this->onETag('"abc"')));
    }

    public function testAnEntityTagThatIsNotItsOwnSatisfiesNothing(): void
    {
        self::assertFalse($this->state()->satisfies($this->onETag('"something-else"')));
    }

    /**
     * **A weak tag matches, and that is not a shortcut.** RFC 9110 §8.8.3.3
     * names a comparison for each HTTP header; WebDAV's `If` is in no such
     * table, and the question it asks decides it.
     *
     * `If` asks "is this resource still in the state I saw", which is exactly
     * what a weak tag answers at the granularity the server has. A byte range
     * asks something else — whether two responses may be spliced — and that
     * is why the file backend marks its tags weak. Comparing them strongly
     * here would mean **no conditional write could ever succeed** against a
     * server whose tags are weak, which is every server backed by a
     * filesystem.
     */
    public function testAWeakTagMatchesTheTagItNames(): void
    {
        self::assertTrue($this->state()->satisfies($this->onETag('W/"abc"')));
    }

    /**
     * And a server whose own tag is weak is answered by a client echoing it
     * back, which is the whole of what `litmus cond_put` does.
     */
    public function testATagThisServerHandedOutWeakComesBackAndMatches(): void
    {
        $state = new ResourceState([], ETag::parse('W/"18f3c-2a"'));

        self::assertTrue($state->satisfies($this->onETag('W/"18f3c-2a"')));
        self::assertFalse($state->satisfies($this->onETag('W/"18f3c-2b"')));
    }

    /**
     * **A resource with no tag satisfies no condition about one.** Not every
     * backend has one for every node, and a comparison against nothing is a
     * comparison that failed.
     */
    public function testAResourceWithNoTagOfItsOwnSatisfiesNoTagCondition(): void
    {
        $state = new ResourceState([self::TOKEN], null);

        self::assertFalse($state->satisfies($this->onETag('"abc"')));
    }

    /**
     * And the negation of that is satisfied: the resource plainly does not
     * have the tag the client named.
     */
    public function testAResourceWithNoTagSatisfiesANegatedTagCondition(): void
    {
        $state = new ResourceState([], null);

        self::assertTrue($state->satisfies($this->onETag('"abc"', true)));
    }

    public function testANegatedTagIsSatisfiedByADifferentTag(): void
    {
        $state = $this->state();

        self::assertTrue($state->satisfies($this->onETag('"something-else"', true)));
        self::assertFalse($state->satisfies($this->onETag('"abc"', true)));
    }

    private function state(): ResourceState
    {
        return new ResourceState([self::TOKEN], ETag::parse('"abc"'));
    }

    private function onETag(string $value, bool $negated = false): IfCondition
    {
        $etag = ETag::parse($value);

        self::assertNotNull($etag, 'The test wrote an entity tag nobody can read.');

        return IfCondition::onETag($etag, $negated);
    }
}

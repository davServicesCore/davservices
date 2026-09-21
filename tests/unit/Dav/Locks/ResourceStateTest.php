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

namespace DavServices\Tests\Unit\Dav\Locks;

use DavServices\Dav\Locks\ResourceState;
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
 * **A condition about a tag is compared strongly.** RFC 9110 §8.8.3.2 keeps
 * weak comparison for "you already have a good enough copy"; `If` guards a
 * write, and a client about to overwrite a resource has to hold the exact
 * bytes it believes it holds.
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
     * RFC 9110 §8.8.3.2: `If` compares strongly, because it guards a write.
     * A weak tag says "equivalent", and equivalent is not the same bytes.
     */
    public function testAWeakTagIsNotAStrongMatch(): void
    {
        self::assertFalse($this->state()->satisfies($this->onETag('W/"abc"')));
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

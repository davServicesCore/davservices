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

namespace DavServices\Tests\Unit\Http;

use DateTimeImmutable;
use DavServices\Http\ConditionalRequest;
use DavServices\Http\Headers;
use DavServices\Http\Precondition;
use DavServices\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-06 and the evaluation order of RFC 9110
 * §13.2.2, which is normative and is where this goes wrong in the wild:
 *
 * 1. `If-Match` — fails: `412`
 * 2. otherwise `If-Unmodified-Since` — fails: `412`
 * 3. `If-None-Match` — matches: `304` for `GET` and `HEAD`, `412` otherwise
 * 4. otherwise, and only for `GET` and `HEAD`, `If-Modified-Since` — `304`
 *
 * The "otherwise" in steps 2 and 4 is the part that is easy to miss: a request
 * carrying both an entity tag and a date is decided by the tag alone, and the
 * date is not consulted at all.
 *
 * Beside the order: the wildcard, which asks whether the resource exists at
 * all; the two comparison functions of R-HTTP-08; and a date that cannot be
 * read, which RFC 9110 §13.1.3 says is to be ignored rather than refused.
 */
#[CoversClass(ConditionalRequest::class)]
#[CoversClass(Precondition::class)]
final class ConditionalRequestTest extends TestCase
{
    private const MODIFIED = 'Sun, 06 Nov 1994 08:49:37 GMT';

    private const EARLIER = 'Sat, 05 Nov 1994 08:49:37 GMT';

    private const LATER = 'Mon, 07 Nov 1994 08:49:37 GMT';

    public function testAnUnconditionalRequestIsMet(): void
    {
        self::assertSame(Precondition::Met, $this->evaluate('GET', []));
    }

    #[DataProvider('ifMatchCases')]
    public function testEvaluatesIfMatchStrongly(string $header, ?string $etag, Precondition $expected): void
    {
        self::assertSame($expected, $this->evaluate('PUT', ['If-Match' => $header], $etag));
    }

    /**
     * RFC 9110 §13.1.1 uses the strong comparison function: a weak tag on
     * either side never matches, because a client that is about to overwrite a
     * resource has to know it holds the exact bytes it thinks it holds.
     *
     * @return iterable<string, array{string, ?string, Precondition}>
     */
    public static function ifMatchCases(): iterable
    {
        yield 'the tag matches' => ['"abc"', '"abc"', Precondition::Met];
        yield 'the tag does not match' => ['"abc"', '"def"', Precondition::Failed];
        yield 'one of several matches' => ['"abc", "def"', '"def"', Precondition::Met];
        yield 'the current tag is weak' => ['"abc"', 'W/"abc"', Precondition::Failed];
        yield 'the given tag is weak' => ['W/"abc"', '"abc"', Precondition::Failed];
        yield 'the resource has no tag' => ['"abc"', null, Precondition::Failed];
        yield 'the wildcard on a resource that is there' => ['*', '"abc"', Precondition::Met];
        yield 'the wildcard on a resource without a tag' => ['*', null, Precondition::Met];
        yield 'nothing readable' => ['garbage', '"abc"', Precondition::Failed];
    }

    /**
     * `If-Match: *` asks whether the resource exists at all, which is how a
     * client says "only if it is already there".
     */
    public function testTheWildcardFailsOnAResourceThatIsNotThere(): void
    {
        self::assertSame(
            Precondition::Failed,
            $this->evaluate('PUT', ['If-Match' => '*'], null, null, false),
        );
    }

    /**
     * `If-None-Match: *` is how a client creates a resource only if nothing is
     * there yet — the one precondition that is meant to pass on an absent
     * resource.
     */
    public function testTheWildcardIsMetOnAResourceThatIsNotThere(): void
    {
        self::assertSame(
            Precondition::Met,
            $this->evaluate('PUT', ['If-None-Match' => '*'], null, null, false),
        );
    }

    #[DataProvider('ifNoneMatchCases')]
    public function testEvaluatesIfNoneMatchWeakly(string $method, string $header, ?string $etag, Precondition $expected): void
    {
        self::assertSame($expected, $this->evaluate($method, ['If-None-Match' => $header], $etag));
    }

    /**
     * RFC 9110 §13.1.2 uses the weak comparison function, and a match means
     * `304` where the client was only reading and `412` where it was about to
     * change something.
     *
     * @return iterable<string, array{string, string, ?string, Precondition}>
     */
    public static function ifNoneMatchCases(): iterable
    {
        yield 'a read of what the client already has' => ['GET', '"abc"', '"abc"', Precondition::NotModified];
        yield 'a head of what the client already has' => ['HEAD', '"abc"', '"abc"', Precondition::NotModified];
        yield 'a write onto what the client already has' => ['PUT', '"abc"', '"abc"', Precondition::Failed];
        yield 'a weak match still matches' => ['GET', '"abc"', 'W/"abc"', Precondition::NotModified];
        yield 'both weak' => ['GET', 'W/"abc"', 'W/"abc"', Precondition::NotModified];
        yield 'no match' => ['GET', '"abc"', '"def"', Precondition::Met];
        yield 'one of several matches' => ['GET', '"abc", "def"', '"def"', Precondition::NotModified];
        yield 'the wildcard on a resource that is there' => ['GET', '*', '"abc"', Precondition::NotModified];
        yield 'the resource has no tag' => ['GET', '"abc"', null, Precondition::Met];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('dateCases')]
    public function testEvaluatesTheDateConditions(string $method, array $headers, ?string $modified, Precondition $expected): void
    {
        self::assertSame(
            $expected,
            $this->evaluate($method, $headers, null, $modified === null ? null : new DateTimeImmutable($modified)),
        );
    }

    /**
     * @return iterable<string, array{string, array<string, string>, ?string, Precondition}>
     */
    public static function dateCases(): iterable
    {
        yield 'unmodified since a later date' => ['PUT', ['If-Unmodified-Since' => self::LATER], self::MODIFIED, Precondition::Met];
        yield 'unmodified since the very second' => ['PUT', ['If-Unmodified-Since' => self::MODIFIED], self::MODIFIED, Precondition::Met];
        yield 'modified after the given date' => ['PUT', ['If-Unmodified-Since' => self::EARLIER], self::MODIFIED, Precondition::Failed];
        yield 'modified since an earlier date' => ['GET', ['If-Modified-Since' => self::EARLIER], self::MODIFIED, Precondition::Met];
        yield 'not modified since the very second' => ['GET', ['If-Modified-Since' => self::MODIFIED], self::MODIFIED, Precondition::NotModified];
        yield 'not modified since a later date' => ['GET', ['If-Modified-Since' => self::LATER], self::MODIFIED, Precondition::NotModified];
        yield 'a modification date the server does not know' => ['GET', ['If-Modified-Since' => self::MODIFIED], null, Precondition::Met];
        yield 'a date the server cannot read' => ['GET', ['If-Modified-Since' => 'yesterday afternoon'], self::MODIFIED, Precondition::Met];
        yield 'an unreadable date on a write' => ['PUT', ['If-Unmodified-Since' => 'yesterday afternoon'], self::MODIFIED, Precondition::Met];
    }

    /**
     * RFC 9110 §5.6.7 still requires the two obsolete date formats to be read,
     * and clients are still out there sending them.
     */
    #[DataProvider('dateFormats')]
    public function testReadsTheThreeDateFormatsHttpAllows(string $date): void
    {
        self::assertSame(
            Precondition::NotModified,
            $this->evaluate('GET', ['If-Modified-Since' => $date], null, new DateTimeImmutable(self::MODIFIED)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dateFormats(): iterable
    {
        yield 'IMF-fixdate' => ['Sun, 06 Nov 1994 08:49:37 GMT'];
        yield 'the obsolete RFC 850 form' => ['Sunday, 06-Nov-94 08:49:37 GMT'];
        yield 'the asctime form' => ['Sun Nov  6 08:49:37 1994'];
    }

    /**
     * Step 2 of §13.2.2 is reached only when `If-Match` is absent. Here the
     * tag says yes and the date would say no, and the tag decides alone.
     */
    public function testTheDateIsNotConsultedWhenAnEntityTagWasGiven(): void
    {
        $outcome = $this->evaluate(
            'PUT',
            ['If-Match' => '"abc"', 'If-Unmodified-Since' => self::EARLIER],
            '"abc"',
            new DateTimeImmutable(self::MODIFIED),
        );

        self::assertSame(Precondition::Met, $outcome);
    }

    /**
     * The same at step 4: `If-None-Match` does not match, so the request is to
     * be answered in full — even though `If-Modified-Since` on its own would
     * have said `304`.
     */
    public function testTheModificationDateIsNotConsultedWhenAnEntityTagWasGiven(): void
    {
        $outcome = $this->evaluate(
            'GET',
            ['If-None-Match' => '"abc"', 'If-Modified-Since' => self::LATER],
            '"def"',
            new DateTimeImmutable(self::MODIFIED),
        );

        self::assertSame(Precondition::Met, $outcome);
    }

    /**
     * A failing `If-Match` decides the request; nothing after it is consulted,
     * not even a matching `If-None-Match` that would mean `304`.
     */
    public function testAFailingEntityTagEndsTheEvaluation(): void
    {
        $outcome = $this->evaluate(
            'GET',
            ['If-Match' => '"abc"', 'If-None-Match' => '"def"'],
            '"def"',
            null,
        );

        self::assertSame(Precondition::Failed, $outcome);
    }

    /**
     * RFC 9110 §13.1.3: `If-Modified-Since` is for `GET` and `HEAD`. On any
     * other method it says nothing, and a `304` to a `PUT` would be nonsense.
     */
    public function testTheModificationDateIsIgnoredOnAMethodThatChangesSomething(): void
    {
        $outcome = $this->evaluate(
            'PUT',
            ['If-Modified-Since' => self::LATER],
            null,
            new DateTimeImmutable(self::MODIFIED),
        );

        self::assertSame(Precondition::Met, $outcome);
    }

    /**
     * Every handler but one is looking at a resource that is there, so that is
     * what the evaluation assumes unless it is told otherwise. Only a create
     * asks about a path with nothing bound to it.
     */
    public function testTheResourceIsTakenToBeThereUnlessSaidOtherwise(): void
    {
        $request = new Request('PUT', '/resource', new Headers(['If-Match' => '*']));

        self::assertSame(Precondition::Met, (new ConditionalRequest($request))->evaluate(null));
    }

    /**
     * @param array<string, string> $headers
     */
    private function evaluate(
        string $method,
        array $headers,
        ?string $etag = null,
        ?DateTimeImmutable $modified = null,
        bool $exists = true,
    ): Precondition {
        $request = new Request($method, '/resource', new Headers($headers));

        return (new ConditionalRequest($request))->evaluate($etag, $modified, $exists);
    }
}

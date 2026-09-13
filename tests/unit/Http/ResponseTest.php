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

use DavServices\Http\Headers;
use DavServices\Http\MalformedResponse;
use DavServices\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-HTTP-01: a value object with a mutability that is
 * stated rather than discovered.
 *
 * Carries: the status, the reason phrase that belongs to it, the headers, and
 * a body that is either a string or a stream — a `PROPFIND` answer is built in
 * memory, a `GET` of a file is not, and the difference has to survive down to
 * the SAPI (R-TREE-02).
 *
 * Knows: the phrases of the statuses a DAV server actually sends, including
 * the ones RFC 4918 added and the ones RFC 9110 renamed.
 *
 * Refuses: a status outside the three digits HTTP allows.
 */
#[CoversClass(Response::class)]
#[CoversClass(MalformedResponse::class)]
final class ResponseTest extends TestCase
{
    public function testAnswersWithNothingInParticularUnlessToldOtherwise(): void
    {
        $response = new Response();

        self::assertSame(200, $response->status());
        self::assertSame('OK', $response->reason());
        self::assertSame([], $response->headers()->toArray());
        self::assertNull($response->body());
        self::assertSame('1.1', $response->protocolVersion());
    }

    #[DataProvider('statuses')]
    public function testKnowsThePhraseThatBelongsToAStatus(int $status, string $reason): void
    {
        self::assertSame($reason, (new Response($status))->reason());
    }

    /**
     * The statuses a DAV server sends. `207` and `423` come from RFC 4918,
     * `507` from RFC 4331, and `413` and `422` carry the names RFC 9110 §15
     * gave them — "Request Entity Too Large" is the name of an older edition.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function statuses(): iterable
    {
        yield 'created' => [201, 'Created'];
        yield 'no content' => [204, 'No Content'];
        yield 'partial content' => [206, 'Partial Content'];
        yield 'multi-status' => [207, 'Multi-Status'];
        yield 'not modified' => [304, 'Not Modified'];
        yield 'bad request' => [400, 'Bad Request'];
        yield 'forbidden' => [403, 'Forbidden'];
        yield 'not found' => [404, 'Not Found'];
        yield 'method not allowed' => [405, 'Method Not Allowed'];
        yield 'conflict' => [409, 'Conflict'];
        yield 'precondition failed' => [412, 'Precondition Failed'];
        yield 'content too large' => [413, 'Content Too Large'];
        yield 'range not satisfiable' => [416, 'Range Not Satisfiable'];
        yield 'locked' => [423, 'Locked'];
        yield 'failed dependency' => [424, 'Failed Dependency'];
        yield 'insufficient storage' => [507, 'Insufficient Storage'];
    }

    /**
     * RFC 9112 §4 allows an empty reason phrase, and a made-up one would be
     * worse than none: a client reads the number, a person reads the text.
     */
    public function testHasNoPhraseForAStatusItDoesNotKnow(): void
    {
        self::assertSame('', (new Response(299))->reason());
    }

    public function testTakesAPhraseOfItsOwn(): void
    {
        self::assertSame('Everything Is Fine', (new Response(200, reason: 'Everything Is Fine'))->reason());
    }

    public function testCarriesTheHeadersAndTheProtocolVersion(): void
    {
        $response = new Response(207, new Headers(['Content-Type' => 'application/xml']), protocolVersion: '1.0');

        self::assertSame('application/xml', $response->headers()->first('content-type'));
        self::assertSame('1.0', $response->protocolVersion());
    }

    public function testCarriesABodyBuiltInMemory(): void
    {
        self::assertSame('<multistatus/>', (new Response(207, body: '<multistatus/>'))->body());
    }

    /**
     * A file is not read into memory to be sent, so what the node handed over
     * has to reach the SAPI as it is (R-TREE-02).
     */
    public function testCarriesABodyThatIsStillAStream(): void
    {
        $stream = fopen('php://memory', 'r+b');

        self::assertIsResource($stream);
        self::assertSame($stream, (new Response(200, body: $stream))->body());
    }

    public function testReplacesTheStatusAndTheReasonTogether(): void
    {
        $response = (new Response())->withStatus(423);

        self::assertSame(423, $response->status());
        self::assertSame('Locked', $response->reason());
    }

    public function testReplacesTheStatusWithAReasonOfItsOwn(): void
    {
        self::assertSame('Gone Fishing', (new Response())->withStatus(503, 'Gone Fishing')->reason());
    }

    public function testReplacesTheBody(): void
    {
        self::assertSame('<error/>', (new Response(body: 'first'))->withBody('<error/>')->body());
    }

    public function testDropsTheBody(): void
    {
        self::assertNull((new Response(body: 'first'))->withBody(null)->body());
    }

    /**
     * A range of a file is served from the file's own stream rather than from
     * a copy of the part that was asked for, so the answer has to say how much
     * of that stream belongs to it (R-HTTP-05, R-TREE-02).
     */
    public function testCarriesHowMuchOfItsBodyToSend(): void
    {
        $stream = fopen('php://memory', 'r+b');

        self::assertIsResource($stream);

        $response = new Response(206, body: $stream, bodyLength: 500);

        self::assertSame(500, $response->bodyLength());
    }

    public function testSendsAllOfItsBodyUnlessToldOtherwise(): void
    {
        self::assertNull((new Response(200, body: 'everything'))->bodyLength());
    }

    public function testReplacingTheBodyReplacesHowMuchOfItToSend(): void
    {
        $response = (new Response(206, body: 'part', bodyLength: 2))->withBody('all of it');

        self::assertNull($response->bodyLength());
    }

    public function testSetsAHeader(): void
    {
        self::assertSame('"abc"', (new Response())->withHeader('ETag', '"abc"')->headers()->first('etag'));
    }

    public function testAddsToAHeaderThatMayRepeat(): void
    {
        $response = (new Response())
            ->withHeader('WWW-Authenticate', 'Basic realm="dav"')
            ->withAddedHeader('WWW-Authenticate', 'Digest realm="dav"');

        self::assertSame(
            ['Basic realm="dav"', 'Digest realm="dav"'],
            $response->headers()->all('WWW-Authenticate'),
        );
    }

    public function testRemovesAHeader(): void
    {
        $response = (new Response(200, new Headers(['ETag' => '"abc"'])))->withoutHeader('etag');

        self::assertFalse($response->headers()->has('ETag'));
    }

    /**
     * A response passes through every plugin that has something to add. One
     * that a plugin could alter behind the back of whoever holds it would make
     * the answer impossible to account for, so each of these hands back a new
     * response and leaves this one as it was.
     */
    public function testWritingLeavesTheOriginalUntouched(): void
    {
        $response = new Response(200, new Headers(['ETag' => '"abc"']), 'first');

        $response->withStatus(404);
        $response->withBody('second');
        $response->withHeader('ETag', '"def"');
        $response->withAddedHeader('Vary', 'Brief');
        $response->withoutHeader('ETag');

        self::assertSame(200, $response->status());
        self::assertSame('first', $response->body());
        self::assertSame(['ETag' => ['"abc"']], $response->headers()->toArray());
    }

    /**
     * The outermost statuses are part of the contract rather than a curiosity:
     * an off-by-one in the guard would refuse an answer the server is entitled
     * to send, and `100` is one a `PUT` with `Expect: 100-continue` needs.
     */
    #[DataProvider('boundaryStatuses')]
    public function testAcceptsTheOutermostStatusesHttpAllows(int $status): void
    {
        self::assertSame($status, (new Response($status))->status());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function boundaryStatuses(): iterable
    {
        yield 'the lowest' => [100];
        yield 'the highest' => [599];
    }

    #[DataProvider('refusedStatuses')]
    public function testRefusesAStatusThatIsNotOne(int $status): void
    {
        $this->expectException(MalformedResponse::class);

        new Response($status);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function refusedStatuses(): iterable
    {
        yield 'zero' => [0];
        yield 'two digits' => [99];
        yield 'four digits' => [1000];
        yield 'negative' => [-200];
    }

    public function testRefusesSuchAStatusWhenReplacingOneToo(): void
    {
        $this->expectException(MalformedResponse::class);

        (new Response())->withStatus(42);
    }

    /**
     * A caller that has never heard of this library still catches the SPL type.
     */
    public function testTheErrorIsAnInvalidArgumentException(): void
    {
        self::assertInstanceOf(InvalidArgumentException::class, new MalformedResponse('boom'));
    }
}

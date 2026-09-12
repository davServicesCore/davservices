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

namespace DavServices\Tests\Unit\Exception;

use DavServices\Exception\BadGateway;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Conflict;
use DavServices\Exception\DavException;
use DavServices\Exception\Forbidden;
use DavServices\Exception\IHttpFailure;
use DavServices\Exception\InsufficientStorage;
use DavServices\Exception\Locked;
use DavServices\Exception\MethodNotAllowed;
use DavServices\Exception\NotFound;
use DavServices\Exception\NotImplemented;
use DavServices\Exception\PayloadTooLarge;
use DavServices\Exception\PreconditionFailed;
use DavServices\Exception\RangeNotSatisfiable;
use DavServices\Exception\Unauthorized;
use DavServices\Exception\UnsupportedMediaType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test list, derived from R-XML-06 (a defined exception carrying a status and
 * a `DAV:error` body) and R-DAV-04 (the status a refused operation reports).
 *
 * Per failure: the HTTP status it reports, and that the status is also the
 * exception code, so a handler that knows nothing about this library still
 * sees it.
 *
 * Shared behaviour: the message survives, a cause can be chained, there is no
 * error element unless one is given, and every failure is both an IHttpFailure
 * and an ordinary RuntimeException.
 */
#[CoversClass(DavException::class)]
#[CoversClass(BadGateway::class)]
#[CoversClass(BadRequest::class)]
#[CoversClass(Conflict::class)]
#[CoversClass(Forbidden::class)]
#[CoversClass(InsufficientStorage::class)]
#[CoversClass(Locked::class)]
#[CoversClass(MethodNotAllowed::class)]
#[CoversClass(NotFound::class)]
#[CoversClass(NotImplemented::class)]
#[CoversClass(PayloadTooLarge::class)]
#[CoversClass(PreconditionFailed::class)]
#[CoversClass(RangeNotSatisfiable::class)]
#[CoversClass(Unauthorized::class)]
#[CoversClass(UnsupportedMediaType::class)]
final class DavExceptionTest extends TestCase
{
    /**
     * @param class-string<DavException> $failure
     */
    #[DataProvider('failures')]
    public function testReportsTheHttpStatusOfTheFailure(string $failure, int $status): void
    {
        self::assertSame($status, (new $failure())->status());
    }

    /**
     * A caller catching the SPL type still gets the status without knowing the
     * interface, because PHP prints and logs the code of an exception.
     *
     * @param class-string<DavException> $failure
     */
    #[DataProvider('failures')]
    public function testTheExceptionCodeIsTheStatus(string $failure, int $status): void
    {
        self::assertSame($status, (new $failure())->getCode());
    }

    /**
     * @param class-string<DavException> $failure
     */
    #[DataProvider('failures')]
    public function testEveryFailureCanBeCaughtByItsSharedTypes(string $failure, int $status): void
    {
        $thrown = new $failure();

        self::assertInstanceOf(IHttpFailure::class, $thrown);
        self::assertInstanceOf(DavException::class, $thrown);
        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertGreaterThan(0, $status);
    }

    /**
     * @return iterable<string, array{class-string<DavException>, int}>
     */
    public static function failures(): iterable
    {
        yield 'a request the library cannot parse' => [BadRequest::class, 400];
        yield 'no credentials, or the wrong ones' => [Unauthorized::class, 401];
        yield 'understood but refused' => [Forbidden::class, 403];
        yield 'no such resource' => [NotFound::class, 404];
        yield 'the method does not apply here' => [MethodNotAllowed::class, 405];
        yield 'the parent collection is missing' => [Conflict::class, 409];
        yield 'a condition of the request failed' => [PreconditionFailed::class, 412];
        yield 'the body exceeds the configured limit' => [PayloadTooLarge::class, 413];
        yield 'the media type cannot be stored here' => [UnsupportedMediaType::class, 415];
        yield 'the requested range lies outside the resource' => [RangeNotSatisfiable::class, 416];
        yield 'a lock stands in the way' => [Locked::class, 423];
        yield 'the method is not implemented' => [NotImplemented::class, 501];
        yield 'an upstream server misbehaved' => [BadGateway::class, 502];
        yield 'the resource does not fit' => [InsufficientStorage::class, 507];
    }

    public function testKeepsTheMessage(): void
    {
        self::assertSame('Depth: infinity is disabled.', (new Forbidden('Depth: infinity is disabled.'))->getMessage());
    }

    public function testHasNoMessageOfItsOwn(): void
    {
        self::assertSame('', (new NotFound())->getMessage());
    }

    /**
     * The cause matters in a log: a backend failure that surfaces as a `507`
     * is useless to diagnose once the original error is gone.
     */
    public function testChainsTheCause(): void
    {
        $cause = new RuntimeException('disk full');

        self::assertSame($cause, (new InsufficientStorage('No room left.', null, $cause))->getPrevious());
    }

    public function testCarriesNoErrorElementUnlessOneIsGiven(): void
    {
        self::assertNull((new Forbidden('Refused.'))->errorElement());
    }

    /**
     * RFC 4918 §16 lets a refusal name its precondition inside a `DAV:error`
     * body. The element is per throw site rather than per status: a `403` can
     * be `propfind-finite-depth` in one place and `need-privileges` in another.
     */
    public function testCarriesTheErrorElementOfItsPrecondition(): void
    {
        $failure = new Forbidden('Depth: infinity is disabled.', '{DAV:}propfind-finite-depth');

        self::assertSame('{DAV:}propfind-finite-depth', $failure->errorElement());
    }
}

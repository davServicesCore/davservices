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

namespace DavServices\Tests\Unit\Backend;

use DavServices\Backend\IAuthBackend;
use PHPUnit\Framework\TestCase;

/**
 * What every `IAuthBackend` owes, whoever wrote it.
 *
 * **This is the seam where a wrong answer is a break-in**, so the contract is
 * stricter about what must *not* be visible than about what must be. Three
 * things it insists on.
 *
 * **Every refusal looks the same.** A wrong password, a user-id nobody has,
 * an account somebody disabled — all of them are null. A backend that told
 * them apart would be answering "does this user-id exist?" to anybody who
 * asked, which is a question no client is entitled to and every attacker
 * wants.
 *
 * **The name, not the path.** RFC 3744 principals hang wherever an
 * application mounted them, and a backend knows no more about that than
 * {@see \DavServices\Backend\IPrincipalBackend} does.
 *
 * **The octets as they arrived.** RFC 7617 §2 leaves the character encoding
 * of Basic credentials deliberately undefined, so nothing above converts
 * them and nothing here may either. A password of `123` followed by U+00A3
 * is the six octets the client sent.
 *
 * ## What this contract cannot test, and says instead
 *
 * The seam also owes **the same work whether the user-id exists or not** —
 * an early return for an unknown one tells an attacker which user-ids exist,
 * in the one currency no firewall filters. **Time is not a thing a test
 * suite can assert reliably**: a loaded machine, a warm cache and a garbage
 * collector all move it more than a hash does. So what is tested here is
 * that the two refusals are *indistinguishable in the answer*, and the rest
 * is written in {@see IAuthBackend} where whoever writes a backend reads it.
 *
 * Saying that plainly is better than a timing assertion that passes on a
 * quiet machine and fails in CI for reasons nobody can chase.
 */
abstract class AuthBackendContract extends TestCase
{
    public function testGoodCredentialsNameTheirPrincipal(): void
    {
        self::assertSame('alice', $this->backend()->principalFor('alice', 'open sesame'));
    }

    /**
     * **The user-id and the principal need not be the same.** Somebody signs
     * in as `c.carter` and is the principal `carol`; that mapping is exactly
     * the kind of thing a deployment has and a protocol library cannot guess.
     */
    public function testTheUserIdAndThePrincipalNeedNotMatch(): void
    {
        self::assertSame('carol', $this->backend()->principalFor('c.carter', 'hunter2'));
    }

    public function testAWrongPasswordIsNobody(): void
    {
        self::assertNull($this->backend()->principalFor('alice', 'not it'));
    }

    public function testAUserIdNobodyHasIsNobody(): void
    {
        self::assertNull($this->backend()->principalFor('nobody', 'open sesame'));
    }

    /**
     * **And the two refusals are the same refusal.** Telling them apart is
     * telling an attacker which user-ids exist — the difference between
     * guessing passwords for a name you know is real and guessing names.
     */
    public function testAWrongPasswordAndAnUnknownUserAreTheSameAnswer(): void
    {
        $backend = $this->backend();

        self::assertSame(
            $backend->principalFor('nobody', 'open sesame'),
            $backend->principalFor('alice', 'not it'),
        );
    }

    /**
     * An empty password is a wrong password, not a way in. A storage that
     * held an empty hash and compared loosely would open every account at
     * once.
     */
    public function testAnEmptyPasswordIsNobody(): void
    {
        self::assertNull($this->backend()->principalFor('alice', ''));
    }

    /**
     * And an empty user-id names nobody, whatever is sent with it.
     */
    public function testAnEmptyUserIdIsNobody(): void
    {
        self::assertNull($this->backend()->principalFor('', 'open sesame'));
    }

    /**
     * **RFC 7617 §2: the encoding is deliberately undefined**, so the octets
     * arrive as the client sent them and a backend compares them as they are.
     * Dagmar's password is `123` followed by U+00A3 — the same six octets as
     * the example in §2.1.
     */
    public function testAPasswordOutsideAsciiIsComparedAsTheOctetsItIs(): void
    {
        self::assertSame('dagmar', $this->backend()->principalFor('dagmar', "123\xC2\xA3"));
    }

    /**
     * The same password written in another encoding is another password.
     * That is not pedantry: it is what "the encoding is undefined" means, and
     * a backend that normalised would let one client in and keep another out
     * for sending the same thing.
     */
    public function testThatPasswordInAnotherEncodingIsAnotherPassword(): void
    {
        // The same characters in ISO-8859-1: one octet for the pound sign.
        self::assertNull($this->backend()->principalFor('dagmar', "123\xA3"));
    }

    /**
     * A password with a colon in it is ordinary. The plugin above splits on
     * the first colon only (RFC 7617 §2), so what reaches a backend still
     * has the rest of them in it.
     */
    public function testAPasswordWithColonsIsOrdinary(): void
    {
        self::assertSame('erin', $this->backend()->principalFor('erin', 'pass:word:with:colons'));
    }

    /**
     * The storage under test, holding Alice, who signs in under her own name;
     * Carol, who signs in as `c.carter`; Dagmar, whose password is not ASCII;
     * and Erin, whose password has colons in it.
     */
    abstract protected function backend(): IAuthBackend;
}

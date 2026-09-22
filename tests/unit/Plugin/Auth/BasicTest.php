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

namespace DavServices\Tests\Unit\Plugin\Auth;

use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\ExceptionRaised;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Auth\Basic;
use DavServices\Tests\Unit\Backend\MemoryAuthBackend;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test list, derived from RFC 7617 and RFC 7235.
 *
 * **This is the piece everything else in P3 was waiting for.** The seam has
 * been there since P3-06 — the core asks `CurrentPrincipalRequested` and
 * somebody answers — and since P3-12b the core widens that answer into every
 * group the person is in. What was missing is the front of it: who is there
 * at all.
 *
 * ## The three things implementations get wrong
 *
 * **"the first colon in a user-pass string separates user-id and password
 * from one another; text after the first colon is part of the password"**
 * (§2). A password with colons in it is ordinary — `pass:word` is a password,
 * not a malformed credential — and splitting on every colon would lock its
 * owner out for good.
 *
 * **"both scheme and parameter names are matched case-insensitively"** (§2).
 * A client sending `basic` is sending Basic.
 *
 * **The character encoding is deliberately undefined.** §2: "this
 * specification continues to leave the default encoding undefined, as long as
 * it is compatible with US-ASCII". So nothing here converts anything — the
 * octets go to the backend as they arrived. RFC 7617's own example is the
 * test: user `test`, password `123` followed by U+00A3, base64
 * `dGVzdDoxMjPCow==`.
 *
 * ## The challenge
 *
 * RFC 7235 §3.1: "The server generating a 401 response **MUST** send a
 * WWW-Authenticate header field containing at least one challenge applicable
 * to the target resource." §2 of RFC 7617 makes `realm` REQUIRED in that
 * challenge and `charset` optional with the single value `UTF-8`.
 *
 * **A refusal means different things to a stranger and to somebody signed
 * in.** RFC 7235 §3.1 again: `401` "indicates that the request has not been
 * applied because it **lacks valid authentication credentials**". Somebody
 * who signed in and still may not have it has been refused authorisation, and
 * that is `403` — answering `401` would send a client round the sign-in loop
 * for ever.
 */
#[CoversClass(Basic::class)]
final class BasicTest extends TestCase
{
    private const REALM = 'davServices test';

    /**
     * Nobody has said who they are, so nobody is there. Not an error: an
     * anonymous request is a perfectly good request, and R-PRIV-03 has it
     * hold nothing rather than fail.
     */
    public function testWithoutCredentialsNobodyIsThere(): void
    {
        self::assertNull($this->whoIsThere(null));
    }

    /**
     * §2: base64 of the user-id, a colon, and the password.
     */
    public function testGoodCredentialsNameTheirPrincipal(): void
    {
        self::assertSame('principals/alice', $this->whoIsThere($this->credentials('alice', 'open sesame')));
    }

    /**
     * §2: "both scheme and parameter names are matched case-insensitively".
     */
    #[DataProvider('theSchemeInEveryCase')]
    public function testTheSchemeNameIsMatchedWithoutRegardToCase(string $scheme): void
    {
        $header = $scheme . ' ' . base64_encode('alice:open sesame');

        self::assertSame('principals/alice', $this->whoIsThere($header));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function theSchemeInEveryCase(): iterable
    {
        yield 'as written' => ['Basic'];

        yield 'lower' => ['basic'];

        yield 'upper' => ['BASIC'];

        yield 'mixed' => ['bAsIc'];
    }

    /**
     * **§2: "text after the first colon is part of the password".** A
     * password with colons in it is ordinary, and a server that split on
     * every colon would lock its owner out with no way to tell why.
     */
    public function testEverythingAfterTheFirstColonIsThePassword(): void
    {
        self::assertSame(
            'principals/carol',
            $this->whoIsThere($this->credentials('carol', 'pass:word:with:colons')),
        );
    }

    /**
     * **RFC 7617 §2.1's own example, byte for byte.** The user is `test` and
     * the password is `123` followed by U+00A3, which in UTF-8 is `C2 A3`;
     * the credentials are `dGVzdDoxMjPCow==`. Nothing here converts anything
     * — §2 leaves the encoding undefined on purpose, so a server that
     * transcoded would be answering a question nobody settled.
     */
    public function testTheOctetsReachTheBackendUnchanged(): void
    {
        self::assertSame('principals/test', $this->whoIsThere('Basic dGVzdDoxMjPCow=='));
    }

    public function testAWrongPasswordIsNobody(): void
    {
        self::assertNull($this->whoIsThere($this->credentials('alice', 'not it')));
    }

    /**
     * And so is a user-id nobody has — the same answer, because telling the
     * two apart tells an attacker which user-ids exist.
     */
    public function testAUserNobodyHasIsNobody(): void
    {
        self::assertNull($this->whoIsThere($this->credentials('nobody', 'open sesame')));
    }

    /**
     * **§2: "The user-id and password MUST NOT contain any control
     * characters."** So credentials carrying one are refused rather than
     * passed on: a newline in a user-id is how a header ends up somewhere it
     * was never meant to go.
     */
    #[DataProvider('credentialsWithAControlCharacter')]
    public function testCredentialsWithAControlCharacterAreRefused(string $userPass): void
    {
        self::assertNull($this->whoIsThere('Basic ' . base64_encode($userPass)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function credentialsWithAControlCharacter(): iterable
    {
        yield 'a newline in the user-id' => ["ali\nce:open sesame"];

        yield 'a newline in the password' => ["alice:open\nsesame"];

        yield 'a null byte' => ["alice:open\0sesame"];

        yield 'a delete' => ["alice:open\x7Fsesame"];
    }

    /**
     * Anything that is not the credentials this scheme defines is nobody,
     * and quietly: a malformed header is a client's mistake, not a reason to
     * fail a request that may not have needed authentication at all.
     */
    #[DataProvider('headersThatSayNothing')]
    public function testAHeaderThatSaysNothingIsNobody(string $header): void
    {
        self::assertNull($this->whoIsThere($header));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headersThatSayNothing(): iterable
    {
        yield 'another scheme' => ['Bearer abc123'];

        yield 'nothing after the scheme' => ['Basic'];

        yield 'not base64' => ['Basic !!!not base64!!!'];

        // No colon at all: there is no password, and guessing that the whole
        // of it is a user-id would be inventing one.
        yield 'no colon' => ['Basic ' . 'YWxpY2U='];

        yield 'empty' => [''];
    }

    /**
     * **RFC 7235 §3.1: a `401` MUST carry a challenge**, and RFC 7617 §2
     * makes `realm` REQUIRED in it. Without one a client has been told to
     * authenticate and not told how.
     */
    public function testARefusalWithoutCredentialsBecomesAChallenge(): void
    {
        $response = $this->refused(null);

        self::assertSame(401, $response->status());
        self::assertSame(
            sprintf('Basic realm="%s", charset="UTF-8"', self::REALM),
            $response->headers()->first('WWW-Authenticate'),
        );
    }

    /**
     * **And a refusal *with* credentials stays a `403`.** §3.1 says `401`
     * means the request "lacks valid authentication credentials"; somebody
     * who signed in and still may not have it lacks nothing of the kind. A
     * `401` there would send a client round the sign-in loop for ever.
     */
    public function testARefusalWithCredentialsStaysARefusal(): void
    {
        $response = $this->refused($this->credentials('alice', 'open sesame'));

        self::assertSame(403, $response->status());
        self::assertNull($response->headers()->first('WWW-Authenticate'));
    }

    /**
     * Credentials that named nobody are the same as none at all: the client
     * gets the challenge, which is what lets it try again.
     */
    public function testARefusalWithBadCredentialsBecomesAChallengeToo(): void
    {
        $response = $this->refused($this->credentials('alice', 'not it'));

        self::assertSame(401, $response->status());
    }

    /**
     * A failure that is not a refusal is left alone. The plugin answers the
     * question it was given, and `404` is not that question.
     */
    public function testAnotherFailureIsLeftAlone(): void
    {
        $server = $this->server();

        $response = $server->handle(new Request('GET', '/nothing-here'));

        self::assertSame(404, $response->status());
        self::assertNull($response->headers()->first('WWW-Authenticate'));
    }

    /**
     * **The backend is asked once for a request, however often the core
     * asks.** Every guard in the access control plugin asks who is there, and
     * a backend that verified the password each time would hash it a dozen
     * times for one request.
     */
    public function testTheBackendIsAskedOncePerRequest(): void
    {
        $backend = $this->backend();
        $server = $this->server($backend);
        $request = new Request('GET', '/work.ics', headers: new Headers([
            'Authorization' => $this->credentials('alice', 'open sesame'),
        ]));

        $events = $server->events();

        $events->emit(new CurrentPrincipalRequested($request));
        $events->emit(new CurrentPrincipalRequested($request));
        $events->emit(new CurrentPrincipalRequested($request));

        self::assertSame(1, $backend->asked);
    }

    /**
     * And a second request is asked about again: the answer is remembered for
     * one request, not for the life of the server — the same rule as the
     * memoising privilege resolver, and for the same reason.
     */
    public function testASecondRequestIsAskedAboutAgain(): void
    {
        $backend = $this->backend();
        $server = $this->server($backend);

        foreach (['/work.ics', '/work.ics'] as $target) {
            $server->events()->emit(new CurrentPrincipalRequested(new Request('GET', $target, headers: new Headers([
                'Authorization' => $this->credentials('alice', 'open sesame'),
            ]))));
        }

        self::assertSame(2, $backend->asked);
    }

    /**
     * **RFC 7617 §2: the realm is REQUIRED**, so there is no server without
     * one. An empty realm would put `realm=""` in the challenge, which names
     * no protection space at all and leaves a client with nothing to
     * remember credentials against (§2.2).
     */
    public function testAServerWithoutARealmIsRefusedAtTheDoor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Basic(new Server(new Tree(new MemoryCollection(''))), $this->backend(), '');
    }

    /**
     * **Both listeners asked directly at least once.** One reached only
     * through the emitter is one Xdebug collects no branch data for, and the
     * coverage gate would report decisions as untaken that every test takes.
     */
    public function testNamesWhoIsThereWhenAskedDirectly(): void
    {
        $plugin = $this->plugin();

        $named = new CurrentPrincipalRequested($this->asking($this->credentials('alice', 'open sesame')));
        $stranger = new CurrentPrincipalRequested($this->asking(null));

        $plugin->nameWhoIsThere($named);
        $plugin->nameWhoIsThere($stranger);

        self::assertSame('principals/alice', $named->principal());
        self::assertNull($stranger->principal());
    }

    /**
     * And the challenge, all three ways: a refusal to a stranger, a refusal
     * to somebody signed in, and a failure that is no refusal at all.
     */
    public function testChallengesAStrangerWhenAskedDirectly(): void
    {
        $plugin = $this->plugin();

        $stranger = new ExceptionRaised($this->asking(null), new Forbidden('no'));
        $signedIn = new ExceptionRaised(
            $this->asking($this->credentials('alice', 'open sesame')),
            new Forbidden('no'),
        );
        $missing = new ExceptionRaised($this->asking(null), new NotFound('nothing here'));

        $plugin->challengeAStranger($stranger);
        $plugin->challengeAStranger($signedIn);
        $plugin->challengeAStranger($missing);

        self::assertSame(401, $stranger->response()?->status());
        self::assertNull($signedIn->response(), 'authorisation, not authentication');
        self::assertNull($missing->response(), 'not a refusal at all');
    }

    /**
     * A failure that is no HTTP failure — a bug, a driver throwing — is not
     * this plugin's to answer either. It says who is asking; it does not
     * decide what every other kind of trouble means.
     */
    public function testAFailureThatIsNoHttpFailureIsLeftAlone(): void
    {
        $event = new ExceptionRaised($this->asking(null), new RuntimeException('the disc went away'));

        $this->plugin()->challengeAStranger($event);

        self::assertNull($event->response());
    }

    private function plugin(): Basic
    {
        return new Basic(new Server(new Tree($this->tree())), $this->backend(), self::REALM);
    }

    private function asking(?string $header): Request
    {
        return new Request(
            'GET',
            '/work.ics',
            headers: $header === null ? new Headers() : new Headers(['Authorization' => $header]),
        );
    }

    private function credentials(string $userId, string $password): string
    {
        return 'Basic ' . base64_encode($userId . ':' . $password);
    }

    private function whoIsThere(?string $header): ?string
    {
        $server = $this->server();
        $headers = $header === null ? new Headers() : new Headers(['Authorization' => $header]);

        return $server->events()
            ->emit(new CurrentPrincipalRequested(new Request('GET', '/work.ics', headers: $headers)))
            ->principal();
    }

    /**
     * A request that the tree refuses, so that the plugin has a refusal to
     * turn into a challenge.
     */
    private function refused(?string $header): Response
    {
        $root = new MemoryCollection('');
        $file = new MemoryFile('work.ics', 'BEGIN:VCALENDAR');

        $file->refuseReading();
        $root->add($file);

        $server = $this->server($this->backend(), $root);
        $headers = $header === null ? new Headers() : new Headers(['Authorization' => $header]);

        return $server->handle(new Request('GET', '/work.ics', headers: $headers));
    }

    private function server(?MemoryAuthBackend $backend = null, ?MemoryCollection $root = null): Server
    {
        $root ??= $this->tree();

        $server = new Server(new Tree($root));
        $get = new Get($server);

        $server->onMethod('GET', $get(...));

        (new Basic($server, $backend ?? $this->backend(), self::REALM))->register();

        return $server;
    }

    private function tree(): MemoryCollection
    {
        $root = new MemoryCollection('');

        $root->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));

        return $root;
    }

    private function backend(): MemoryAuthBackend
    {
        return new MemoryAuthBackend([
            'alice' => ['open sesame', 'alice'],
            'carol' => ['pass:word:with:colons', 'carol'],
            // RFC 7617 §2.1's own example: "123" followed by U+00A3.
            'test' => ["123\xC2\xA3", 'test'],
        ]);
    }
}

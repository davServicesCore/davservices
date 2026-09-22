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

namespace DavServices\Plugin\Auth;

use DavServices\Backend\IAuthBackend;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\ExceptionRaised;
use DavServices\Dav\Server;
use DavServices\Exception\IHttpFailure;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Uri\Path;
use InvalidArgumentException;

/**
 * Answers who is asking, from `Authorization: Basic` (RFC 7617).
 *
 * **This is the front of a seam that has been waiting since P3-06.** The core
 * asks {@see CurrentPrincipalRequested} and something answers; since P3-12b
 * it widens that answer into every group the person is in. What was missing
 * was the beginning: who is there at all.
 *
 *     (new Basic($server, $yourAuthBackend, 'Your server'))->register();
 *
 * ## Three things this scheme is usually got wrong about
 *
 * **The first colon is the only one that separates** (§2): "text after the
 * first colon is part of the password". `pass:word` is an ordinary password,
 * and a server that split on every colon would lock its owner out with no
 * way to see why.
 *
 * **The scheme name is matched without regard to case** (§2), so a client
 * sending `basic` is sending Basic.
 *
 * **Nothing here converts anything.** §2 leaves the character encoding of the
 * credentials deliberately undefined, "as long as it is compatible with
 * US-ASCII" — so the octets reach the backend as they arrived. The challenge
 * says `charset="UTF-8"` (§2.1), which is advice to the client about what to
 * send, not licence for this to transcode what it receives.
 *
 * **Control characters are refused** rather than passed on: §2 has it that
 * "the user-id and password MUST NOT contain any control characters", and a
 * newline inside a user-id is how something ends up in a header it was never
 * meant to reach.
 *
 * ## The challenge, and when it is not one
 *
 * RFC 7235 §3.1: a `401` **MUST** carry a `WWW-Authenticate` header with at
 * least one challenge, and RFC 7617 §2 makes `realm` REQUIRED in it. So a
 * refusal met by a stranger becomes that challenge.
 *
 * **A refusal met by somebody who signed in stays a refusal.** §3.1 says
 * `401` means the request "lacks valid authentication credentials", and
 * somebody authenticated lacks nothing of the kind — they simply may not have
 * it. Answering `401` there would send a client round the sign-in loop for
 * ever, asking for a password that was never the problem.
 *
 * ## What it does not do
 *
 * **It keeps no passwords and compares none** — that is
 * {@see IAuthBackend}, and the reasons are written there. And it does not
 * decide what anybody may do: this says who is asking, access control says
 * what they hold, and the two are apart on purpose.
 *
 * RFC 7617 §4 is worth reading before switching this on: Basic "results in
 * the cleartext transmission of the user's password", and SHOULD NOT be used
 * without HTTPS to protect anything valuable. This library cannot enforce
 * that — it never sees the transport — so it is said here instead.
 */
final class Basic
{
    private const SCHEME = 'basic';

    /**
     * Remembered for one request, because every guard asks again.
     */
    private ?Request $answeredFor = null;

    private ?string $answer = null;

    /**
     * @param string $realm The protection space this server calls itself
     *                      (RFC 7617 §2, REQUIRED in a challenge). A client
     *                      remembers credentials against it (§2.2)
     * @param string $principalsAt Where the principal collection is mounted,
     *                             because a backend answers with a member
     *                             name and only the application knows the path
     *
     * @throws InvalidArgumentException If the realm is empty, which would name
     *                                  no protection space at all
     */
    public function __construct(
        private readonly Server $server,
        private readonly IAuthBackend $backend,
        private readonly string $realm,
        private readonly string $principalsAt = 'principals',
    ) {
        if ($realm === '') {
            throw new InvalidArgumentException('RFC 7617 §2 requires a realm, and an empty one names nothing.');
        }
    }

    /**
     * Switches on the answer to "who is asking", and the challenge.
     */
    public function register(): void
    {
        $events = $this->server->events();

        $events->on(CurrentPrincipalRequested::class, $this->nameWhoIsThere(...));
        $events->on(ExceptionRaised::class, $this->challengeAStranger(...));
    }

    /**
     * Says who is asking, where the credentials name somebody.
     *
     * The answer is worked out once for a request and kept for it. Every
     * guard in the access control plugin asks this, and a backend that
     * verified the password each time would hash it a dozen times over one
     * request. It is kept for **one** request, on the same rule as the
     * memoising privilege resolver: a memory that lived longer would go on
     * naming somebody whose password had just been changed.
     */
    public function nameWhoIsThere(CurrentPrincipalRequested $event): void
    {
        $request = $event->request();

        if ($this->answeredFor !== $request) {
            $this->answeredFor = $request;
            $this->answer = $this->principalFor($request);
        }

        if ($this->answer !== null) {
            $event->answerWith($this->answer);
        }
    }

    /**
     * Turns a refusal met by a stranger into the challenge RFC 7235 §3.1
     * requires — and leaves every other failure alone.
     */
    public function challengeAStranger(ExceptionRaised $event): void
    {
        $failure = $event->failure();

        if (!$failure instanceof IHttpFailure || $failure->status() !== 403) {
            return;
        }

        if ($this->principalFor($event->request()) !== null) {
            // Signed in and still refused: authorisation, not authentication.
            return;
        }

        $event->answerWith(
            (new Response(401))->withHeader(
                'WWW-Authenticate',
                // §2.1: the only allowed value, and advice rather than a rule
                // — it tells a client what to send, and costs nothing where
                // the client ignores it.
                sprintf('Basic realm="%s", charset="UTF-8"', $this->realm),
            ),
        );
    }

    /**
     * The principal path the credentials name, or null.
     */
    private function principalFor(Request $request): ?string
    {
        $credentials = self::credentialsIn($request);

        if ($credentials === null) {
            return null;
        }

        [$userId, $password] = $credentials;
        $name = $this->backend->principalFor($userId, $password);

        return $name === null ? null : Path::join($this->principalsAt, $name);
    }

    /**
     * The user-id and password an `Authorization` header carries, or null
     * where it carries none this scheme can read.
     *
     * A header that says nothing is not an error: an anonymous request is a
     * perfectly good request, and a malformed one is a client's mistake
     * rather than a reason to fail something that may not have needed
     * credentials at all.
     *
     * @return array{string, string}|null
     */
    private static function credentialsIn(Request $request): ?array
    {
        $header = $request->headers()->first('Authorization');

        if ($header === null) {
            return null;
        }

        $divider = strpos($header, ' ');

        // §2: "both scheme and parameter names are matched
        // case-insensitively".
        if ($divider === false || strtolower(substr($header, 0, $divider)) !== self::SCHEME) {
            return null;
        }

        $userPass = base64_decode(trim(substr($header, $divider + 1)), true);

        if ($userPass === false) {
            return null;
        }

        // §2: "the first colon in a user-pass string separates user-id and
        // password from one another; text after the first colon is part of
        // the password". Which colon it is decides whose password works, so
        // the first one is looked for rather than left to a split limit.
        $colon = strpos($userPass, ':');

        // §2: "The user-id and password MUST NOT contain any control
        // characters."
        if ($colon === false || self::holdsAControlCharacter($userPass)) {
            return null;
        }

        return [substr($userPass, 0, $colon), substr($userPass, $colon + 1)];
    }

    /**
     * Whether a string carries any of the control characters RFC 5234 calls
     * `CTL` — the C0 range, and delete.
     */
    private static function holdsAControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}

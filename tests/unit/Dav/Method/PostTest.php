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

namespace DavServices\Tests\Unit\Dav\Method;

use DavServices\Dav\Method\Post;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\BadRequest;
use DavServices\Exception\NotFound;
use DavServices\Exception\UnsupportedMediaType;
use DavServices\Http\Body;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list for `POST`, derived from RFC 9110 §9.3.3 and RFC 4918 §9.5.
 *
 * **WebDAV leaves this method alone on purpose.** RFC 4918 §9.5 is one
 * sentence long:
 *
 * > This specification does not define the behavior of POST for collections.
 *
 * And RFC 9110 §9.3.3 says why it can afford to: "The POST method requests
 * that the target resource process the representation enclosed in the request
 * according to the resource's own specific semantics." So what a `POST` means
 * is the resource's business, and the extensions that use it say which body
 * they take — `caldav-sharing` §5.3.2 a `CS:share`, §5.4.1 a
 * `CS:invite-reply`, RFC 6638 §6 a free/busy request.
 *
 * **So this is a dispatcher and nothing else**, the same shape {@see Report}
 * has and for the same reason: a body naming what is wanted, and a server
 * that knows how to answer that name.
 *
 * It reads the body **once** and hands over what it found, so that no handler
 * parses the request twice. It looks for the target **first**, because every
 * `POST` of this family is about the resource at the request target. And it
 * refuses a root element nobody answers with `415`, which is the reading
 * {@see \DavServices\CalDav\Method\MkCalendar} already makes of a body this
 * server does not take — there being no `DAV:` precondition for `POST` to
 * name instead, and a bare `403` reading as "not for you".
 *
 * **Every handler is offered on every resource**, as every report is. A
 * server whose `POST` differed by resource would need this list asked for per
 * path; the handler is what knows which resource its body belongs on, and
 * {@see \DavServices\Plugin\Sharing} is the first to say so.
 */
#[CoversClass(Post::class)]
final class PostTest extends TestCase
{
    private const SOMETHING = '{https://dav.services/test}something';

    private const ANOTHER = '{https://dav.services/test}another';

    /**
     * A server that was not given the method answers `501`, which is the case
     * that keeps `POST` optional (R-ARC-02): RFC 4918 §9.5 defines no
     * behaviour, so a server owes none.
     */
    public function testAServerWithoutTheMethodDoesNotPost(): void
    {
        $server = new Server(new Tree($this->tree()));

        self::assertSame(501, $server->handle($this->request())->status());
    }

    /**
     * RFC 9110 §9.3.3: the body is processed "according to the resource's own
     * specific semantics", and the root element is what names which of them.
     */
    public function testItAnswersWhateverTheRootElementNames(): void
    {
        $response = $this->post(self::SOMETHING, static fn (): Response => new Response(200, body: 'done'));

        self::assertSame(200, $response->status());
        self::assertSame('done', $response->body());
    }

    /**
     * **The body is read once and handed over.** A handler that parsed the
     * request again would be parsing what the method already holds, and two
     * readings are two chances to disagree about what the client asked for.
     */
    public function testItHandsTheHandlerWhatTheClientSent(): void
    {
        $seen = [];

        $this->post(
            self::SOMETHING,
            static function (Request $request, Element $sent) use (&$seen): Response {
                $seen[] = $request->method();
                $seen[] = $sent->name();
                $seen[] = ($sent->children()[0] ?? null)?->name();

                return new Response(200);
            },
            '<t:something xmlns:t="https://dav.services/test"><t:detail/></t:something>',
        );

        self::assertSame(['POST', self::SOMETHING, '{https://dav.services/test}detail'], $seen);
    }

    /**
     * And two handlers are told apart by the name they were given — the whole
     * point of dispatching on the root element rather than on the path, since
     * `caldav-sharing` puts two different bodies on two different resources
     * and RFC 6638 a third.
     */
    public function testItTellsTwoHandlersApartByTheirRootElement(): void
    {
        $server = new Server(new Tree($this->tree()));
        $post = new Post($server);

        $post->on(self::SOMETHING, static fn (): Response => new Response(200, body: 'the first'));
        $post->on(self::ANOTHER, static fn (): Response => new Response(200, body: 'the second'));

        $server->onMethod('POST', $post(...));

        self::assertSame('the first', $server->handle($this->request())->body());
        self::assertSame(
            'the second',
            $server->handle($this->request('<t:another xmlns:t="https://dav.services/test"/>'))->body(),
        );
    }

    /**
     * **A root element nobody answers is `415`.** RFC 9110 §15.5.16 has that
     * status for a server "refusing to service the request because the content
     * is in a format not supported by this method on the target resource",
     * which is this case said in the memo's own words.
     *
     * Not `403`: this library's own `REPORT` refuses an unknown report with
     * `403` **and** `DAV:supported-report`, and the precondition is what makes
     * that answer readable. `POST` has no such element in any of these memos,
     * and a bare `403` reads as "not for you" — a client would go on trying
     * with other credentials.
     */
    public function testItRefusesARootElementNobodyAnswers(): void
    {
        $response = $this->post(
            self::SOMETHING,
            static fn (): Response => new Response(200),
            '<t:other xmlns:t="https://dav.services/test"/>',
        );

        self::assertSame(415, $response->status());
    }

    /**
     * A body nobody can read is the reader's `400`, and it is left to the
     * reader: a second guard for the same thing would be a second place to
     * keep right.
     */
    public function testItRefusesABodyNobodyCanRead(): void
    {
        $response = $this->post(self::SOMETHING, static fn (): Response => new Response(200), 'this is no XML');

        self::assertSame(400, $response->status());
    }

    /**
     * **And a `POST` with no body at all names nothing.** There is no default
     * handler, for the reason `REPORT` has no default report: choosing one
     * would be answering a question nobody asked.
     */
    public function testItRefusesAPostThatCarriesNoBody(): void
    {
        $server = $this->server(self::SOMETHING, static fn (): Response => new Response(200));

        self::assertSame(400, $server->handle(new Request('POST', '/calendars/work'))->status());
    }

    /**
     * **The target is looked for first.** A client that posted to something
     * that is not there has that wrong with its request whatever the body
     * says, and being told the body was unreadable would send it looking in
     * the wrong place.
     */
    public function testTheTargetIsLookedForBeforeTheBodyIsRead(): void
    {
        $post = new Post(new Server(new Tree($this->tree())));

        $this->expectException(NotFound::class);

        $post(new Request('POST', '/calendars/missing', body: new Body('this is no XML')));
    }

    /**
     * **And the handler does not run.** A `404` worked out after the handler
     * had already run would be a handler that had changed something for a
     * resource nobody has.
     */
    public function testAPostAtNothingIsNeverHandled(): void
    {
        $ran = 0;
        $post = new Post(new Server(new Tree($this->tree())));

        $post->on(self::SOMETHING, static function () use (&$ran): Response {
            ++$ran;

            return new Response(200);
        });

        try {
            $post($this->request(target: '/calendars/missing'));
        } catch (NotFound) {
            // The refusal is the case above; this one counts.
        }

        self::assertSame(0, $ran);
    }

    /**
     * **Both ways out, asked directly at least once.** A branch reached only
     * through the server is one Xdebug collects no data for, and the gate
     * would report decisions as untaken that every case takes.
     */
    public function testItAnswersAndRefusesWhenAskedDirectly(): void
    {
        $server = new Server(new Tree($this->tree()));
        $post = new Post($server);

        $post->on(self::SOMETHING, static fn (): Response => new Response(200, body: 'done'));

        self::assertSame('done', $post($this->request())->body());

        $this->expectException(UnsupportedMediaType::class);

        $post($this->request('<t:other xmlns:t="https://dav.services/test"/>'));
    }

    public function testItRefusesAnEmptyBodyWhenAskedDirectly(): void
    {
        $post = new Post(new Server(new Tree($this->tree())));

        $this->expectException(BadRequest::class);

        $post(new Request('POST', '/calendars/work'));
    }

    private function tree(): MemoryCollection
    {
        $calendars = new MemoryCollection('calendars');

        $calendars->add((new MemoryCollection('work'))->add(new MemoryFile('one.ics', 'BEGIN:VCALENDAR')));

        return (new MemoryCollection(''))->add($calendars);
    }

    private function request(?string $body = null, string $target = '/calendars/work'): Request
    {
        return new Request(
            'POST',
            $target,
            body: new Body($body ?? '<t:something xmlns:t="https://dav.services/test"/>'),
        );
    }

    private function server(string $name, callable $handler): Server
    {
        $server = new Server(new Tree($this->tree()));
        $post = new Post($server);

        $post->on($name, $handler);
        $server->onMethod('POST', $post(...));

        return $server;
    }

    private function post(string $name, callable $handler, ?string $body = null): Response
    {
        return $this->server($name, $handler)->handle($this->request($body));
    }
}

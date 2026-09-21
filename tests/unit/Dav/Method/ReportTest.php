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

use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3253 §3.6 and §3.1.5.
 *
 * **`REPORT` is the method everything else in this protocol family is built
 * on**, and it is remarkably little on its own: a body naming what is wanted,
 * and a server that knows how to answer that name. Everything that makes a
 * report interesting — searching principals, querying calendars, telling a
 * client what changed — is the report, not the method.
 *
 * So this class does three things and no more. It reads the body once and
 * hands the report the element it found, so that no report parses the request
 * twice. It refuses a name it does not know with `403` and
 * `DAV:supported-report`, which is what §3.6 asks for and what tells a client
 * to stop asking. And it answers `DAV:supported-report-set`, because **the
 * thing that knows the reports is the only thing that can**.
 *
 * That last one is why this method class is registered rather than merely
 * handed to the server: a method that brought a property with it and left the
 * wiring to the application would sooner or later be wired half way, and a
 * `supported-report-set` that said "none" while reports were answered is a
 * client that never asks for them.
 *
 * What is **not** here: `Depth`. RFC 3253 lets each report define what depth
 * means for it, and most define it differently — so the request is handed
 * over whole and the report reads what it needs.
 */
#[CoversClass(Report::class)]
final class ReportTest extends TestCase
{
    private const SOMETHING = '{https://dav.services/test}something';

    /**
     * A server that was not given the method answers `501`, and that is the
     * test that keeps this honest about being optional (R-ARC-02).
     */
    public function testAServerWithoutTheMethodDoesNotReport(): void
    {
        $server = new Server(new Tree($this->tree()));

        self::assertSame(501, $server->handle($this->request(self::SOMETHING))->status());
    }

    /**
     * RFC 3253 §3.6: the body names the report, and the server answers it.
     */
    public function testAnswersAReportItWasGiven(): void
    {
        $response = $this->report(
            self::SOMETHING,
            static fn (): Response => new Response(207, body: 'the answer'),
        );

        self::assertSame(207, $response->status());
        self::assertSame('the answer', $response->body());
    }

    /**
     * **The body is read once and handed over.** A report that had to parse
     * the request again would be parsing what the method has already held in
     * its hands — and two readings are two chances to disagree about what
     * the client asked for.
     */
    public function testHandsTheReportWhatTheClientAskedFor(): void
    {
        $seen = [];

        $this->report(
            self::SOMETHING,
            static function (Request $request, Element $asked) use (&$seen): Response {
                $seen[] = $request->method();
                $seen[] = $asked->name();
                $seen[] = ($asked->children()[0] ?? null)?->name();

                return new Response(207);
            },
            '<t:something xmlns:t="https://dav.services/test"><t:detail/></t:something>',
        );

        self::assertSame(['REPORT', self::SOMETHING, '{https://dav.services/test}detail'], $seen);
    }

    /**
     * RFC 3253 §3.6: a report this server does not know is `403` with
     * `DAV:supported-report`. **That precondition is what tells a client to
     * stop asking** — a bare `403` reads as "not for you", and a client would
     * go on trying with other credentials.
     */
    public function testRefusesAReportItDoesNotKnow(): void
    {
        $response = $this->report(self::SOMETHING, null, '<t:other xmlns:t="https://dav.services/test"/>');

        self::assertSame(403, $response->status());
        self::assertStringContainsString('<d:supported-report/>', (string) $response->body());
    }

    /**
     * A `REPORT` without a body names nothing at all. There is no default
     * report, and choosing one would be answering a question nobody asked.
     */
    public function testRefusesAReportThatNamesNothing(): void
    {
        $server = $this->server(self::SOMETHING, static fn (): Response => new Response(207));

        self::assertSame(400, $server->handle(new Request('REPORT', '/calendars/work.ics'))->status());
    }

    public function testRefusesABodyNobodyCanRead(): void
    {
        $response = $this->report(self::SOMETHING, null, 'this is no XML');

        self::assertSame(400, $response->status());
    }

    /**
     * RFC 3253 §3.1.5: `DAV:supported-report-set` names what may be asked
     * for. A report that is not in it does not exist as far as a client is
     * concerned, so the list has to come from the same place the answers do.
     */
    public function testSaysWhichReportsItAnswers(): void
    {
        $body = $this->propFind(self::SOMETHING, static fn (): Response => new Response(207));

        // The prefix the writer picks for a namespace of its own is its own
        // business; where the element sits is not.
        self::assertStringContainsString('<d:supported-report><d:report><', $body);
        self::assertStringContainsString(':something/></d:report></d:supported-report>', $body);
    }

    /**
     * And it answers **before** the live properties do, so that the empty
     * answer of a server with no reports does not win by being registered
     * first. The order is set by this library rather than by whoever wires
     * the application together.
     */
    public function testItsAnswerBeatsTheEmptyOne(): void
    {
        $body = $this->propFind(self::SOMETHING, static fn (): Response => new Response(207));

        self::assertStringNotContainsString('<d:supported-report-set/>', $body);
    }

    /**
     * A server with the method and no reports answers an empty set — which
     * is a different statement from having no such property, and the one
     * P2-08 chose deliberately.
     */
    public function testAServerWithNoReportsSaysSo(): void
    {
        $body = $this->propFind(null, null);

        self::assertStringContainsString('<d:supported-report-set/>', $body);
    }

    /**
     * Several reports are all named, in the order they were registered: the
     * list is what a client reads to decide what to try first.
     */
    public function testNamesEveryReportItWasGiven(): void
    {
        $server = new Server(new Tree($this->tree()));
        $report = new Report($server);

        $report->on(self::SOMETHING, static fn (): Response => new Response(207));
        $report->on('{https://dav.services/test}another', static fn (): Response => new Response(207));
        $report->register();

        $body = (string) $this->askForTheReportSet($server)->body();

        self::assertLessThan(
            (int) strpos($body, 'another'),
            (int) strpos($body, 'something'),
            'in the order they were registered',
        );
    }

    /**
     * The property is answered for whatever resource was asked about: a
     * report is a thing this server can do, not a thing one file can do.
     */
    public function testAnswersThePropertyForACollectionToo(): void
    {
        $server = $this->server(self::SOMETHING, static fn (): Response => new Response(207));

        $body = (string) $server->handle(new Request(
            'PROPFIND',
            '/calendars',
            headers: new Headers(['Depth' => '0']),
            body: new Body('<D:propfind xmlns:D="DAV:"><D:prop><D:supported-report-set/></D:prop></D:propfind>'),
        ))->body();

        self::assertStringContainsString('<d:supported-report>', $body);
    }

    /**
     * **Both entry points asked directly at least once.** One reached only
     * through the server or the emitter is one Xdebug collects no branch data
     * for, and the gate would report decisions as untaken that every test
     * takes.
     */
    public function testAnswersAndRefusesWhenAskedDirectly(): void
    {
        $server = new Server(new Tree($this->tree()));
        $report = new Report($server);

        $report->on(self::SOMETHING, static fn (): Response => new Response(207, body: 'the answer'));

        self::assertSame('the answer', $report($this->request(self::SOMETHING))->body());

        $this->expectException(Forbidden::class);

        $report($this->request(self::SOMETHING, '<t:other xmlns:t="https://dav.services/test"/>'));
    }

    public function testRefusesAnEmptyBodyWhenAskedDirectly(): void
    {
        $report = new Report(new Server(new Tree($this->tree())));

        $this->expectException(BadRequest::class);

        $report(new Request('REPORT', '/calendars/work.ics'));
    }

    /**
     * And the property, both ways round: asked for, and not asked for —
     * **nothing is worked out that nobody wanted.**
     */
    public function testAnswersThePropertyWhenAskedDirectly(): void
    {
        $report = new Report(new Server(new Tree($this->tree())));

        $report->on(self::SOMETHING, static fn (): Response => new Response(207));

        $result = new PropFindResult('calendars/work.ics', PropFindForm::Named, ['{DAV:}supported-report-set']);

        $report->describe(new PropertiesRequested($result, new MemoryFile('work.ics', '')));

        self::assertArrayHasKey('{DAV:}supported-report-set', $result->byStatus()[200] ?? []);
    }

    public function testWorksOutThePropertyNobodyAskedForNotAtAll(): void
    {
        $report = new Report(new Server(new Tree($this->tree())));

        $report->on(self::SOMETHING, static fn (): Response => new Response(207));

        $result = new PropFindResult('calendars/work.ics', PropFindForm::Named, ['{DAV:}getetag']);

        $report->describe(new PropertiesRequested($result, new MemoryFile('work.ics', '')));

        self::assertSame([], $result->byStatus()[200] ?? []);
    }

    private function tree(): MemoryCollection
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));
        $root->add($calendars);

        return $root;
    }

    private function request(string $name, ?string $body = null): Request
    {
        return new Request(
            'REPORT',
            '/calendars/work.ics',
            body: new Body($body ?? sprintf('<t:%s xmlns:t="https://dav.services/test"/>', 'something')),
        );
    }

    private function server(?string $name, ?callable $handler): Server
    {
        $server = new Server(new Tree($this->tree()));
        $report = new Report($server);

        if ($name !== null && $handler !== null) {
            $report->on($name, $handler);
        }

        $report->register();

        $propFind = new PropFind($server);
        $live = new LiveProperties();

        $server->events()->on(PropertiesRequested::class, $live(...));
        $server->onMethod('PROPFIND', $propFind(...));

        return $server;
    }

    private function report(string $name, ?callable $handler, ?string $body = null): Response
    {
        return $this->server($name, $handler)->handle($this->request($name, $body));
    }

    private function propFind(?string $name, ?callable $handler): string
    {
        return (string) $this->askForTheReportSet($this->server($name, $handler))->body();
    }

    private function askForTheReportSet(Server $server): Response
    {
        $propFind = new PropFind($server);
        $live = new LiveProperties();

        $server->events()->on(PropertiesRequested::class, $live(...));
        $server->onMethod('PROPFIND', $propFind(...));

        return $server->handle(new Request(
            'PROPFIND',
            '/calendars/work.ics',
            headers: new Headers(['Depth' => '0']),
            body: new Body('<D:propfind xmlns:D="DAV:"><D:prop><D:supported-report-set/></D:prop></D:propfind>'),
        ));
    }
}

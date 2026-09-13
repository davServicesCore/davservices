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

use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\AfterCopy;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Event\BeforeCopy;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadGateway;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\NotFound;
use DavServices\Exception\PreconditionFailed;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-07 (`Destination`, `Overwrite` and `Depth`,
 * and the statuses that go with them) and RFC 4918 §9.8.
 *
 * A `COPY` is the first method that is about **two** paths, and most of what
 * can go wrong is about the second one. The destination may be on another
 * server, may already hold something the client does not want replaced, may
 * lie inside what is being copied — and each of those is a different answer.
 *
 * The one worth naming: **a destination inside the source is refused**. A
 * client that asks to copy `/calendars` into `/calendars/backup` is asking for
 * a copy that contains itself, and a server that starts on it does not stop.
 */
#[CoversClass(Copy::class)]
#[CoversClass(BeforeCopy::class)]
#[CoversClass(AfterCopy::class)]
#[CoversClass(BeforeBind::class)]
#[CoversClass(AfterBind::class)]
final class CopyTest extends TestCase
{
    public function testCopiesAFileToSomewhereNew(): void
    {
        $root = $this->tree();

        $response = $this->copy($root, '/work.ics', '/archive.ics');

        self::assertSame(201, $response->status());
        self::assertTrue($root->hasChild('archive.ics'));
        self::assertTrue($root->hasChild('work.ics'), 'A copy took the original with it.');
    }

    /**
     * RFC 4918 §9.8.4: `Overwrite: T` is the default, and what was there is
     * replaced. The `204` is how a client learns it replaced something rather
     * than created it.
     */
    public function testReplacesWhatIsAlreadyThere(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('archive.ics', 'the old one'));

        $response = $this->copy($root, '/work.ics', '/archive.ics');

        self::assertSame(204, $response->status());
        self::assertSame('a meeting', $this->contentOf($root, 'archive.ics'));
    }

    /**
     * `Overwrite: F` is a client saying it does not know what is there and
     * would rather be told. Replacing it anyway would destroy something it
     * never named.
     */
    public function testRefusesToReplaceWhereItWasToldNotTo(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('archive.ics', 'the old one'));

        $response = $this->copy($root, '/work.ics', '/archive.ics', ['Overwrite' => 'F']);

        self::assertSame(412, $response->status());
        self::assertSame('the old one', $this->contentOf($root, 'archive.ics'), 'It was replaced all the same.');
    }

    public function testRefusesAnOverwriteItCannotRead(): void
    {
        self::assertSame(400, $this->copy($this->tree(), '/work.ics', '/archive.ics', ['Overwrite' => 'maybe'])->status());
    }

    public function testRefusesARequestWithNoDestination(): void
    {
        $server = $this->server($this->tree());

        self::assertSame(400, $server->handle(new Request('COPY', '/work.ics'))->status());
    }

    /**
     * RFC 4918 §9.8.5: a destination on another server is a `502`. This one
     * cannot write there, and saying `403` would have the client believe it is
     * a matter of permission.
     */
    public function testRefusesADestinationOnAnotherServer(): void
    {
        $response = $this->copy($this->tree(), '/work.ics', 'http://elsewhere.example/archive.ics');

        self::assertSame(502, $response->status());
    }

    /**
     * The same for a destination on this host that this server does not serve:
     * it belongs to another application, and this one cannot write into it.
     */
    public function testRefusesADestinationItDoesNotServe(): void
    {
        $root = $this->tree();
        $server = $this->server($root, baseUri: '/dav/');

        $response = $server->handle($this->request('/dav/work.ics', '/elsewhere/archive.ics'));

        self::assertSame(502, $response->status());
    }

    public function testTakesADestinationThatNamesThisHost(): void
    {
        $root = $this->tree();

        $response = $this->copy($root, '/work.ics', 'http://dav.example/archive.ics', ['Host' => 'dav.example']);

        self::assertSame(201, $response->status());
    }

    /**
     * A proxy tells the server one authority and the client builds its URL
     * from another. Refusing that would break every deployment that terminates
     * TLS somewhere else, so the port is not compared.
     */
    public function testTakesADestinationOnThisHostWhateverThePortSays(): void
    {
        $root = $this->tree();

        $response = $this->copy($root, '/work.ics', 'http://dav.example/archive.ics', ['Host' => 'dav.example:8443']);

        self::assertSame(201, $response->status());
    }

    public function testRefusesADestinationThatIsNoUrl(): void
    {
        self::assertSame(400, $this->copy($this->tree(), '/work.ics', 'http://:80')->status());
    }

    /**
     * The root is a member of no collection, so nothing can be put in its
     * place. It matters that this is refused **before** anything is removed:
     * what is at the destination goes first, and a request that was never
     * going to work would have emptied the server on its way to failing.
     */
    public function testRefusesTheRootAsADestination(): void
    {
        $root = $this->tree();

        $response = $this->copy($root, '/work.ics', 'http://dav.example', ['Host' => 'dav.example']);

        self::assertSame(403, $response->status());
        self::assertTrue($root->hasChild('work.ics'), 'The server was emptied on the way to a refusal.');
    }

    /**
     * RFC 3986 §3.2.2: a host is matched without regard to case, on both sides
     * of the comparison. A client that wrote its own URL in capitals is not
     * asking for another server.
     */
    public function testComparesTheHostWithoutRegardToCase(): void
    {
        $root = $this->tree();

        $response = $this->copy($root, '/work.ics', 'http://DAV.example/archive.ics', ['Host' => 'dav.EXAMPLE']);

        self::assertSame(201, $response->status());
    }

    /**
     * RFC 4918 §10.6 spells `Overwrite` in capitals, and RFC 5234 §2.3 has a
     * letter matched without regard to case all the same.
     */
    public function testTakesAnOverwriteHoweverItIsSpelt(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('archive.ics', 'the old one'));

        self::assertSame(412, $this->copy($root, '/work.ics', '/archive.ics', ['Overwrite' => 'f'])->status());
    }

    /**
     * The slash matters here as everywhere: `/calendars2` does not lie inside
     * `/calendars`, and a server that thought so would refuse a copy nobody
     * could talk it into making another way.
     */
    public function testTakesADestinationThatMerelyBeginsLikeTheSource(): void
    {
        $root = $this->treeWithACalendar();

        $response = $this->copy($root, '/calendars', '/calendars2');

        self::assertSame(201, $response->status());
        self::assertTrue($this->collectionIn($root, 'calendars2')->hasChild('work.ics'));
    }

    /**
     * A request that names nothing is answered as such, whatever else is wrong
     * with it: the client has more to learn from `404` than from a complaint
     * about a header it can fix without the resource ever appearing.
     */
    public function testAnswersNotFoundBeforeItComplainsAboutTheHeaders(): void
    {
        $server = $this->server($this->tree());

        self::assertSame(404, $server->handle(new Request('COPY', '/nowhere.ics'))->status());
    }

    public function testRefusesADestinationWhoseCollectionIsNotThere(): void
    {
        self::assertSame(409, $this->copy($this->tree(), '/work.ics', '/nowhere/archive.ics')->status());
    }

    /**
     * RFC 4918 §9.8.3: `Depth: infinity` is the default for a collection, and
     * everything below it comes along.
     */
    public function testCopiesACollectionWithEverythingBelowIt(): void
    {
        $root = $this->treeWithACalendar();

        $response = $this->copy($root, '/calendars', '/archive');

        self::assertSame(201, $response->status());
        self::assertTrue($this->collectionIn($root, 'archive')->hasChild('work.ics'));
    }

    /**
     * `Depth: 0` copies the collection and nothing in it, which is a client
     * asking for the collection itself and its properties.
     */
    public function testCopiesACollectionOnItsOwnWhereItIsAsked(): void
    {
        $root = $this->treeWithACalendar();

        $this->copy($root, '/calendars', '/archive', ['Depth' => '0']);

        self::assertSame([], $this->collectionIn($root, 'archive')->children());
    }

    /**
     * RFC 4918 §9.8.3 allows `0` and `infinity` and nothing else: a `COPY` of
     * one level deep is not an operation this protocol has.
     */
    public function testRefusesADepthTheProtocolDoesNotHaveForACopy(): void
    {
        self::assertSame(400, $this->copy($this->treeWithACalendar(), '/calendars', '/archive', ['Depth' => '1'])->status());
    }

    /**
     * A copy that would contain itself. A server that starts on one does not
     * stop until something runs out.
     */
    public function testRefusesADestinationInsideTheSource(): void
    {
        $root = $this->treeWithACalendar();

        $response = $this->copy($root, '/calendars', '/calendars/backup');

        self::assertSame(403, $response->status());
        self::assertFalse($this->collectionIn($root, 'calendars')->hasChild('backup'));
    }

    public function testRefusesADestinationThatIsTheSource(): void
    {
        self::assertSame(403, $this->copy($this->tree(), '/work.ics', '/work.ics')->status());
    }

    /**
     * RFC 4918 §9.8.5: a member that could not be copied is named, and the
     * ones that could are not.
     */
    public function testNamesTheMemberThatWouldNotCopy(): void
    {
        $root = $this->tree();
        $calendars = (new MemoryCollection('calendars'))
            ->add((new MemoryFile('work.ics'))->refuseReading())
            ->add(new MemoryFile('home.ics', 'at home'));

        $root->add($calendars);

        $response = $this->copy($root, '/calendars', '/archive');

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:href>/calendars/work.ics</d:href>', (string) $response->body());
        self::assertStringNotContainsString('home.ics', (string) $response->body());
        self::assertTrue($this->collectionIn($root, 'archive')->hasChild('home.ics'), 'What could be copied was not.');
    }

    /**
     * A refusal of the resource the client named is a plain status: a `207`
     * exists to say something about a second resource.
     */
    public function testARefusalOfTheSourceItselfIsAPlainStatus(): void
    {
        $root = $this->tree();
        $root->add((new MemoryFile('locked.ics'))->refuseReading());

        $response = $this->copy($root, '/locked.ics', '/archive.ics');

        self::assertSame(403, $response->status());
        self::assertNull($response->body());
    }

    public function testAnswersNotFoundWhereThereIsNothingToCopy(): void
    {
        self::assertSame(404, $this->copy($this->tree(), '/nowhere.ics', '/archive.ics')->status());
    }

    /**
     * R-ARC-04: the seam a plugin watches a copy through, with both ends of it.
     */
    public function testRaisesTheTwoEventsOfACopy(): void
    {
        /** @var list<string> $seen */
        $seen = [];
        $events = new EventEmitter();

        $events->on(BeforeCopy::class, static function (BeforeCopy $event) use (&$seen): void {
            $seen[] = sprintf('before %s to %s', $event->from(), $event->to());
        });
        $events->on(AfterCopy::class, static function (AfterCopy $event) use (&$seen): void {
            $seen[] = sprintf('after %s to %s', $event->from(), $event->to());
        });

        $this->copy($this->tree(), '/work.ics', '/archive.ics', events: $events);

        self::assertSame(['before work.ics to archive.ics', 'after work.ics to archive.ics'], $seen);
    }

    /**
     * R-ARC-04: and the seam that fires wherever a member appears, whatever
     * put it there.
     */
    public function testRaisesTheTwoEventsOfABind(): void
    {
        /** @var list<string> $seen */
        $seen = [];
        $events = new EventEmitter();

        $events->on(BeforeBind::class, static function (BeforeBind $event) use (&$seen): void {
            $seen[] = 'before ' . $event->path();
        });
        $events->on(AfterBind::class, static function (AfterBind $event) use (&$seen): void {
            $seen[] = 'after ' . $event->path();
        });

        $this->copy($this->tree(), '/work.ics', '/archive.ics', events: $events);

        self::assertSame(['before archive.ics', 'after archive.ics'], $seen);
    }

    public function testAListenerCanRefuseTheWholeCopy(): void
    {
        $root = $this->tree();

        $events = new EventEmitter();
        $events->on(BeforeCopy::class, static function (): void {
            throw new Forbidden('Not into that account.');
        });

        $response = $this->copy($root, '/work.ics', '/archive.ics', events: $events);

        self::assertSame(403, $response->status());
        self::assertFalse($root->hasChild('archive.ics'), 'The listener refused and it was copied all the same.');
    }

    /**
     * Xdebug measures no branch of a method class that is only ever reached
     * through the server's first-class callable, so every decision is asked
     * for directly as well.
     */
    public function testAskedAsAMethodItCopiesTheFile(): void
    {
        $root = $this->tree();

        self::assertSame(201, ($this->method($root))($this->request('/work.ics', '/archive.ics'))->status());
    }

    public function testAskedAsAMethodItReplacesWhatIsAlreadyThere(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('archive.ics', 'the old one'));

        self::assertSame(204, ($this->method($root))($this->request('/work.ics', '/archive.ics'))->status());
    }

    public function testAskedAsAMethodItRefusesToReplaceWhereItWasToldNotTo(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('archive.ics', 'the old one'));

        $this->expectException(PreconditionFailed::class);

        ($this->method($root))($this->request('/work.ics', '/archive.ics', ['Overwrite' => 'F']));
    }

    public function testAskedAsAMethodItRefusesAMissingDestination(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('A COPY needs a Destination.');

        ($this->method($this->tree()))(new Request('COPY', '/work.ics'));
    }

    public function testAskedAsAMethodItRefusesAnotherServer(): void
    {
        $this->expectException(BadGateway::class);

        ($this->method($this->tree()))($this->request('/work.ics', 'http://elsewhere.example/archive.ics'));
    }

    public function testAskedAsAMethodItRefusesADestinationInsideTheSource(): void
    {
        $this->expectException(Forbidden::class);

        ($this->method($this->treeWithACalendar()))($this->request('/calendars', '/calendars/backup'));
    }

    public function testAskedAsAMethodItRaisesNotFoundWhereThereIsNothing(): void
    {
        $this->expectException(NotFound::class);

        ($this->method($this->tree()))($this->request('/nowhere.ics', '/archive.ics'));
    }

    public function testAskedAsAMethodItAnswersAMultiStatus(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))->add((new MemoryFile('work.ics'))->refuseReading()));

        self::assertSame(207, ($this->method($root))($this->request('/calendars', '/archive'))->status());
    }

    private function tree(): MemoryCollection
    {
        return (new MemoryCollection(''))->add(new MemoryFile('work.ics', 'a meeting'));
    }

    private function treeWithACalendar(): MemoryCollection
    {
        $calendars = (new MemoryCollection('calendars'))->add(new MemoryFile('work.ics', 'a meeting'));

        return (new MemoryCollection(''))->add($calendars);
    }

    private function method(MemoryCollection $root): Copy
    {
        return new Copy(new Server(new Tree($root)));
    }

    private function server(MemoryCollection $root, ?EventEmitter $events = null, string $baseUri = '/'): Server
    {
        $server = new Server(new Tree($root), $events, baseUri: $baseUri);
        $copy = new Copy($server);

        $server->onMethod('COPY', $copy(...));

        return $server;
    }

    /**
     * @param array<string, string> $headers
     */
    private function copy(
        MemoryCollection $root,
        string $target,
        string $destination,
        array $headers = [],
        ?EventEmitter $events = null,
    ): Response {
        return $this->server($root, $events)->handle($this->request($target, $destination, $headers));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $target, string $destination, array $headers = []): Request
    {
        return new Request('COPY', $target, new Headers(['Destination' => $destination] + $headers));
    }

    private function collectionIn(MemoryCollection $root, string $name): MemoryCollection
    {
        $child = $root->child($name);

        if (!$child instanceof MemoryCollection) {
            self::fail(sprintf('"%s" is no collection.', $name));
        }

        return $child;
    }

    private function contentOf(MemoryCollection $root, string $name): string
    {
        $child = $root->child($name);

        if (!$child instanceof MemoryFile) {
            self::fail(sprintf('"%s" is no file.', $name));
        }

        return $child->get();
    }
}

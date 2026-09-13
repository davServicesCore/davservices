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

use DavServices\Dav\Event\AfterMove;
use DavServices\Dav\Event\BeforeMove;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-07 and RFC 4918 §9.9.
 *
 * A `MOVE` is a `COPY` that leaves nothing behind, and it shares every refusal
 * with it: the destination on another server, the one that is already taken,
 * the one inside the source. Two things are its own.
 *
 * **A `MOVE` is always the whole thing** (§9.9.2). There is no moving a
 * collection without what is in it: the members would be left with no address
 * to reach them by, which is the same reason a `DELETE` keeps the ancestors of
 * what it could not remove.
 *
 * **A move is not a removal and a creation.** What is moved keeps its
 * identity, and the events say so — a plugin told the resource had been
 * deleted here and created there would throw away exactly what a move
 * preserves.
 */
#[CoversClass(Move::class)]
#[CoversClass(BeforeMove::class)]
#[CoversClass(AfterMove::class)]
final class MoveTest extends TestCase
{
    public function testMovesAFileAndLeavesNothingBehind(): void
    {
        $root = $this->tree();

        $response = $this->move($root, '/work.ics', '/archive.ics');

        self::assertSame(201, $response->status());
        self::assertTrue($root->hasChild('archive.ics'));
        self::assertFalse($root->hasChild('work.ics'), 'The original stayed where it was.');
    }

    public function testReplacesWhatIsAlreadyThere(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('archive.ics', 'the old one'));

        $response = $this->move($root, '/work.ics', '/archive.ics');

        self::assertSame(204, $response->status());
        self::assertSame('a meeting', $this->contentOf($root, 'archive.ics'));
    }

    public function testRefusesToReplaceWhereItWasToldNotTo(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('archive.ics', 'the old one'));

        $response = $this->move($root, '/work.ics', '/archive.ics', ['Overwrite' => 'F']);

        self::assertSame(412, $response->status());
        self::assertTrue($root->hasChild('work.ics'), 'The source went although the move was refused.');
    }

    public function testMovesACollectionWithEverythingBelowIt(): void
    {
        $root = $this->treeWithACalendar();

        $this->move($root, '/calendars', '/archive');

        self::assertFalse($root->hasChild('calendars'));
        self::assertTrue($this->collectionIn($root, 'archive')->hasChild('work.ics'));
    }

    /**
     * RFC 4918 §9.9.2: a `MOVE` on a collection acts as `Depth: infinity` and a
     * client may send nothing else. One that sends `0` believes it is moving
     * the collection and leaving its members — and there is no such operation,
     * because the members would be left with no address to reach them by.
     */
    public function testRefusesAnyDepthButInfinity(): void
    {
        $root = $this->treeWithACalendar();

        $response = $this->move($root, '/calendars', '/archive', ['Depth' => '0']);

        self::assertSame(400, $response->status());
        self::assertTrue($root->hasChild('calendars'), 'It moved although the request was refused.');
    }

    public function testTakesInfinityHoweverItIsSpelt(): void
    {
        self::assertSame(201, $this->move($this->treeWithACalendar(), '/calendars', '/archive', ['Depth' => 'Infinity'])->status());
    }

    public function testRefusesADestinationInsideTheSource(): void
    {
        $root = $this->treeWithACalendar();

        $response = $this->move($root, '/calendars', '/calendars/backup');

        self::assertSame(403, $response->status());
        self::assertTrue($root->hasChild('calendars'));
    }

    public function testRefusesADestinationOnAnotherServer(): void
    {
        self::assertSame(502, $this->move($this->tree(), '/work.ics', 'http://elsewhere.example/archive.ics')->status());
    }

    public function testRefusesADestinationWhoseCollectionIsNotThere(): void
    {
        self::assertSame(409, $this->move($this->tree(), '/work.ics', '/nowhere/archive.ics')->status());
    }

    /**
     * R-ARC-04: `beforeMove` and `afterMove`, with both ends of the move. A
     * plugin that carries something along — the dead properties, an index
     * entry — needs to be told where from and where to in one breath.
     */
    public function testRaisesTheTwoEventsOfAMove(): void
    {
        /** @var list<string> $seen */
        $seen = [];
        $events = new EventEmitter();

        $events->on(BeforeMove::class, static function (BeforeMove $event) use (&$seen): void {
            $seen[] = sprintf('before %s to %s', $event->from(), $event->to());
        });
        $events->on(AfterMove::class, static function (AfterMove $event) use (&$seen): void {
            $seen[] = sprintf('after %s to %s', $event->from(), $event->to());
        });

        $this->move($this->tree(), '/work.ics', '/archive.ics', events: $events);

        self::assertSame(['before work.ics to archive.ics', 'after work.ics to archive.ics'], $seen);
    }

    public function testAListenerCanRefuseTheWholeMove(): void
    {
        $root = $this->tree();

        $events = new EventEmitter();
        $events->on(BeforeMove::class, static function (): void {
            throw new Forbidden('Not out of that account.');
        });

        $response = $this->move($root, '/work.ics', '/archive.ics', events: $events);

        self::assertSame(403, $response->status());
        self::assertTrue($root->hasChild('work.ics'), 'The listener refused and it moved all the same.');
    }

    /**
     * A source that will not be read is not moved, and above all is not
     * removed: a move that lost what it was moving would be the worst answer
     * this method could give.
     */
    public function testLeavesTheSourceWhereItIsWhenItCannotBeCopied(): void
    {
        $root = $this->tree();
        $root->add((new MemoryFile('locked.ics'))->refuseReading());

        $response = $this->move($root, '/locked.ics', '/archive.ics');

        self::assertSame(403, $response->status());
        self::assertTrue($root->hasChild('locked.ics'), 'The source went although the copy failed.');
    }

    /**
     * Xdebug measures no branch of a method class that is only ever reached
     * through the server's first-class callable, so every decision is asked
     * for directly as well.
     */
    public function testAskedAsAMethodItMovesTheFile(): void
    {
        $root = $this->tree();

        self::assertSame(201, ($this->method($root))($this->request('/work.ics', '/archive.ics'))->status());
    }

    public function testAskedAsAMethodItRefusesADepthOtherThanInfinity(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('A MOVE takes what is below it with it.');

        ($this->method($this->treeWithACalendar()))($this->request('/calendars', '/archive', ['Depth' => '0']));
    }

    public function testAskedAsAMethodItRefusesAMissingDestination(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('A MOVE needs a Destination.');

        ($this->method($this->tree()))(new Request('MOVE', '/work.ics'));
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

    private function method(MemoryCollection $root): Move
    {
        return new Move(new Server(new Tree($root)));
    }

    /**
     * @param array<string, string> $headers
     */
    private function move(
        MemoryCollection $root,
        string $target,
        string $destination,
        array $headers = [],
        ?EventEmitter $events = null,
    ): Response {
        $server = new Server(new Tree($root), $events);
        $move = new Move($server);

        $server->onMethod('MOVE', $move(...));

        return $server->handle($this->request($target, $destination, $headers));
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $target, string $destination, array $headers = []): Request
    {
        return new Request('MOVE', $target, new Headers(['Destination' => $destination] + $headers));
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

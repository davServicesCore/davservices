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

use DavServices\Dav\Event\AfterUnbind;
use DavServices\Dav\Event\BeforeUnbind;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\Locked;
use DavServices\Exception\NotFound;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-DAV-06 (`DELETE` on a collection is recursive, and
 * a partial failure is a `207`), R-ARC-04 (the two points a removal is watched
 * at) and R-TREE-05 (what the tree has to forget).
 *
 * The first method that touches more than one node, and the rule that shapes
 * it is RFC 4918 §9.6.1: **a member that cannot be deleted keeps all of its
 * ancestors**. A server that deleted the collection anyway would leave that
 * member with no address to reach it by — data that is still there, still
 * charged for, and can never be got at again.
 *
 * The same rule is why the walk happens here rather than in the backend. A
 * backend asked to delete a whole collection can only say *that* something
 * went wrong, never *which* member it was, and the `207` has to name it.
 *
 * What went is deliberately **not** in that report (§9.6.1 again): a removal
 * that worked needs no explanation, and listing every one of them would make
 * the answer as long as the tree.
 */
#[CoversClass(Delete::class)]
#[CoversClass(BeforeUnbind::class)]
#[CoversClass(AfterUnbind::class)]
final class DeleteTest extends TestCase
{
    public function testDeletesAFile(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt', 'a note'));

        $response = $this->delete($root, '/notes.txt');

        self::assertSame(204, $response->status());
        self::assertNull($response->body(), 'A 204 carries nothing.');
        self::assertFalse($root->hasChild('notes.txt'));
    }

    public function testAnswersNotFoundWhereThereIsNothing(): void
    {
        self::assertSame(404, $this->delete($this->tree(), '/nowhere.txt')->status());
    }

    /**
     * R-DAV-06: a collection goes with everything below it, however deep.
     */
    public function testDeletesACollectionWithEverythingBelowIt(): void
    {
        $root = $this->tree();
        $alice = new MemoryCollection('alice');
        $alice->add(new MemoryFile('work.ics', 'a meeting'));
        $root->add((new MemoryCollection('calendars'))->add($alice));

        $response = $this->delete($root, '/calendars');

        self::assertSame(204, $response->status());
        self::assertFalse($root->hasChild('calendars'));
        self::assertFalse($alice->hasChild('work.ics'), 'What lay below the collection stayed.');
    }

    /**
     * Deepest first, so that a refusal further down is met while everything
     * above it is still there to keep that member reachable.
     */
    public function testDeletesTheDeepestMembersFirst(): void
    {
        $root = $this->tree();
        $alice = (new MemoryCollection('alice'))->add(new MemoryFile('work.ics'));
        $root->add((new MemoryCollection('calendars'))->add($alice));

        /** @var list<string> $seen */
        $seen = [];
        $events = new EventEmitter();
        $events->on(BeforeUnbind::class, static function (BeforeUnbind $event) use (&$seen): void {
            $seen[] = $event->path();
        });

        $this->delete($root, '/calendars', events: $events);

        self::assertSame(['calendars/alice/work.ics', 'calendars/alice', 'calendars'], $seen);
    }

    public function testAMemberThatWillNotGoIsReportedAsMultiStatus(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))->add((new MemoryFile('work.ics'))->refuseDeletion()));

        $response = $this->delete($root, '/calendars');

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:href>/calendars/work.ics</d:href>', (string) $response->body());
        self::assertStringContainsString('HTTP/1.1 403 Forbidden', (string) $response->body());
    }

    /**
     * RFC 4918 §9.6.1: what could not be deleted keeps its ancestors, or the
     * member is left in the tree with nothing addressing it any more.
     */
    public function testAMemberThatStaysKeepsItsAncestors(): void
    {
        $root = $this->tree();
        $calendars = (new MemoryCollection('calendars'))->add((new MemoryFile('work.ics'))->refuseDeletion());
        $root->add($calendars);

        $this->delete($root, '/calendars');

        self::assertTrue($root->hasChild('calendars'), 'The collection went although a member stayed.');
        self::assertTrue($calendars->hasChild('work.ics'));
    }

    /**
     * The report names what went wrong, not what went right.
     */
    public function testWhatWentIsNotNamedInTheReport(): void
    {
        $root = $this->tree();
        $calendars = (new MemoryCollection('calendars'))
            ->add(new MemoryFile('gone.ics'))
            ->add((new MemoryFile('stays.ics'))->refuseDeletion());
        $root->add($calendars);

        $body = (string) $this->delete($root, '/calendars')->body();

        self::assertStringNotContainsString('gone.ics', $body);
        self::assertStringContainsString('stays.ics', $body);
        self::assertFalse($calendars->hasChild('gone.ics'), 'The member that could go was kept.');
    }

    /**
     * Every member that stayed is named, not merely the first of them. A
     * client told about one would put that one right, ask again, and be
     * refused for the next — once for every member, and never told how many
     * are left.
     */
    public function testEveryMemberThatStaysIsNamed(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))
            ->add((new MemoryFile('work.ics'))->refuseDeletion())
            ->add((new MemoryFile('home.ics'))->refuseDeletion()));

        $body = (string) $this->delete($root, '/calendars')->body();

        self::assertStringContainsString('<d:href>/calendars/work.ics</d:href>', $body);
        self::assertStringContainsString('<d:href>/calendars/home.ics</d:href>', $body);
    }

    /**
     * A member that goes after one that stayed does not undo the report of it,
     * and above all does not let the collection go: whether the ancestors
     * survive must not depend on the order the backend lists its members in.
     */
    public function testAMemberThatGoesDoesNotCancelOneThatStayed(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))
            ->add((new MemoryFile('stays.ics'))->refuseDeletion())
            ->add(new MemoryFile('gone.ics')));

        $response = $this->delete($root, '/calendars');

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:href>/calendars/stays.ics</d:href>', (string) $response->body());
        self::assertTrue($root->hasChild('calendars'), 'The collection went although a member stayed.');
    }

    /**
     * A refusal of the request target itself is a plain status: there is no
     * second resource a client would learn anything about from a `207`.
     */
    public function testARefusalOfTheTargetItselfIsAPlainStatus(): void
    {
        $root = $this->tree();
        $root->add((new MemoryFile('notes.txt'))->refuseDeletion());

        $response = $this->delete($root, '/notes.txt');

        self::assertSame(403, $response->status());
        self::assertNull($response->body(), 'A refusal of the target is no multi-status.');
    }

    public function testACollectionThatWillNotBeListedIsRefusedAsTheTarget(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))->refuseListing());

        $response = $this->delete($root, '/calendars');

        self::assertSame(403, $response->status());
        self::assertTrue($root->hasChild('calendars'), 'A collection went without ever being read.');
    }

    /**
     * A collection that will not say what it holds cannot be emptied, and
     * nothing below it may be taken to be gone.
     */
    public function testACollectionThatWillNotBeListedIsNamedAsAMember(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))->add((new MemoryCollection('alice'))->refuseListing()));

        $response = $this->delete($root, '/calendars');

        self::assertSame(207, $response->status());
        self::assertStringContainsString('<d:href>/calendars/alice</d:href>', (string) $response->body());
    }

    /**
     * The precondition is what a client acts on: `lock-token-submitted` tells
     * it to send the token it holds, where a bare `423` tells it nothing.
     */
    public function testTheReportNamesThePreconditionWhereThereIsOne(): void
    {
        $root = $this->tree();
        $locked = (new MemoryFile('work.ics'))->refuseDeletion(new Locked('It is locked.', '{DAV:}lock-token-submitted'));
        $root->add((new MemoryCollection('calendars'))->add($locked));

        $body = (string) $this->delete($root, '/calendars')->body();

        self::assertStringContainsString('HTTP/1.1 423 Locked', $body);
        self::assertStringContainsString('lock-token-submitted', $body);
    }

    /**
     * The message of the refusal is what a person reads where the status alone
     * does not explain itself, which is what `DAV:responsedescription` is for.
     */
    public function testTheReportCarriesTheReasonTheMemberStayed(): void
    {
        $root = $this->tree();
        $refused = (new MemoryFile('work.ics'))->refuseDeletion(new Forbidden('The calendar is read-only.'));
        $root->add((new MemoryCollection('calendars'))->add($refused));

        self::assertStringContainsString(
            'The calendar is read-only.',
            (string) $this->delete($root, '/calendars')->body(),
        );
    }

    /**
     * A refusal that came without a message says nothing rather than saying
     * nothing at length: an empty `DAV:responsedescription` is noise in an
     * answer a client is meant to act on.
     */
    public function testTheReportLeavesOutAnEmptyReason(): void
    {
        $root = $this->tree();
        $refused = (new MemoryFile('work.ics'))->refuseDeletion(new Forbidden());
        $root->add((new MemoryCollection('calendars'))->add($refused));

        self::assertStringNotContainsString(
            'responsedescription',
            (string) $this->delete($root, '/calendars')->body(),
        );
    }

    /**
     * Nothing makes the root of the tree a special case. A backend that will
     * not have it removed refuses like any other node would, and the library
     * does not decide on its behalf that the request is absurd.
     *
     * Asked of the method directly, because the root is the one path with no
     * collection above it to forget, and that decision is only measured on a
     * direct call.
     */
    public function testDeletesTheRootItself(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt'));

        $response = ($this->method($root))(new Request('DELETE', '/'));

        self::assertSame(204, $response->status());
        self::assertFalse($root->hasChild('notes.txt'));
    }

    public function testTheReportIsSentAsXml(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))->add((new MemoryFile('work.ics'))->refuseDeletion()));

        $response = $this->delete($root, '/calendars');

        self::assertStringContainsString('application/xml', (string) $response->headers()->first('Content-Type'));
        self::assertStringContainsString('<d:multistatus', (string) $response->body());
    }

    /**
     * The `href` is a URL, not a path inside the tree: it carries the base the
     * server is mounted at, and it is encoded. A client handed the raw name
     * would go and ask for the wrong resource.
     */
    public function testTheHrefCarriesTheBaseUriAndIsEncoded(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))->add((new MemoryFile('work week.ics'))->refuseDeletion()));

        $body = (string) $this->delete($root, '/dav/calendars', baseUri: '/dav')->body();

        self::assertStringContainsString('<d:href>/dav/calendars/work%20week.ics</d:href>', $body);
    }

    /**
     * RFC 4918 §9.6.1: a client may not send anything but `infinity` on a
     * collection. One that sends `0` believes it is deleting the collection
     * alone, and has to be told that no such operation exists.
     */
    public function testRefusesADepthOtherThanInfinityOnACollection(): void
    {
        $root = $this->tree();
        $calendars = (new MemoryCollection('calendars'))->add(new MemoryFile('work.ics'));
        $root->add($calendars);

        $response = $this->delete($root, '/calendars', ['Depth' => '0']);

        self::assertSame(400, $response->status());
        self::assertTrue($root->hasChild('calendars'), 'The collection went although the request was refused.');
    }

    public function testRefusesDepthOneOnACollection(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        self::assertSame(400, $this->delete($root, '/calendars', ['Depth' => '1'])->status());
    }

    /**
     * RFC 5234 §2.3: what a grammar spells out in letters is case-insensitive.
     */
    public function testTakesInfinityHoweverItIsSpelt(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        self::assertSame(204, $this->delete($root, '/calendars', ['Depth' => 'Infinity'])->status());
    }

    /**
     * A file has nothing below it, so `0` and `infinity` say the same thing
     * about it. Refusing a header that cannot mean anything else would break
     * clients for nothing.
     */
    public function testIgnoresDepthOnAFile(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt'));

        self::assertSame(204, $this->delete($root, '/notes.txt', ['Depth' => '0'])->status());
    }

    /**
     * R-ARC-04: the after-event is where a plugin drops what it kept about a
     * node — its dead properties, its locks, its index entry. It is raised for
     * what actually went, and for nothing else.
     */
    public function testTheAfterEventIsRaisedOnlyForWhatWentAway(): void
    {
        $root = $this->tree();
        $calendars = (new MemoryCollection('calendars'))
            ->add(new MemoryFile('gone.ics'))
            ->add((new MemoryFile('stays.ics'))->refuseDeletion());
        $root->add($calendars);

        /** @var list<string> $seen */
        $seen = [];
        $events = new EventEmitter();
        $events->on(AfterUnbind::class, static function (AfterUnbind $event) use (&$seen): void {
            $seen[] = $event->path();
        });

        $this->delete($root, '/calendars', events: $events);

        self::assertSame(['calendars/gone.ics'], $seen);
    }

    /**
     * A listener refuses by throwing, and its refusal is reported like the
     * backend's own: the member stays, and so does everything above it. This
     * is how the lock plugin of P3 will keep a locked file from going.
     */
    public function testAListenerCanRefuseOneMember(): void
    {
        $root = $this->tree();
        $calendars = (new MemoryCollection('calendars'))->add(new MemoryFile('work.ics'));
        $root->add($calendars);

        $events = new EventEmitter();
        $events->on(BeforeUnbind::class, static function (BeforeUnbind $event): void {
            if ($event->path() === 'calendars/work.ics') {
                throw new Locked('It is locked.', '{DAV:}lock-token-submitted');
            }
        });

        $response = $this->delete($root, '/calendars', events: $events);

        self::assertSame(207, $response->status());
        self::assertStringContainsString('HTTP/1.1 423 Locked', (string) $response->body());
        self::assertTrue($calendars->hasChild('work.ics'), 'The listener refused and the file went all the same.');
    }

    /**
     * R-TREE-05: the tree keeps what it found for the length of a request, and
     * a deletion makes some of that wrong. Handing out a node that is gone is
     * worse than never having cached it, so the proof is that the backend is
     * asked again.
     */
    public function testTheTreeForgetsWhatWentAway(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))->add(new MemoryFile('work.ics')));

        $tree = new Tree($root);
        $server = new Server($tree);
        $delete = new Delete($server);
        $server->onMethod('DELETE', $delete(...));

        $tree->node('calendars');
        $server->handle(new Request('DELETE', '/calendars/work.ics'));
        $tree->node('calendars');

        self::assertSame(2, $root->lookups, 'The collection was handed out from the cache after a member went.');
    }

    /**
     * Xdebug measures no branch of a method class that is only ever reached
     * through the server's first-class callable, so every decision is asked
     * for directly as well.
     */
    public function testAskedAsAMethodItDeletesTheFile(): void
    {
        $root = $this->tree();
        $root->add(new MemoryFile('notes.txt'));

        self::assertSame(204, ($this->method($root))(new Request('DELETE', '/notes.txt'))->status());
    }

    public function testAskedAsAMethodItAnswersAMultiStatus(): void
    {
        $root = $this->tree();
        $root->add((new MemoryCollection('calendars'))->add((new MemoryFile('work.ics'))->refuseDeletion()));

        self::assertSame(207, ($this->method($root))(new Request('DELETE', '/calendars'))->status());
    }

    public function testAskedAsAMethodItRaisesWhatTheTargetRefusedWith(): void
    {
        $root = $this->tree();
        $root->add((new MemoryFile('notes.txt'))->refuseDeletion());

        $this->expectException(Forbidden::class);

        ($this->method($root))(new Request('DELETE', '/notes.txt'));
    }

    public function testAskedAsAMethodItRefusesADepthOtherThanInfinity(): void
    {
        $root = $this->tree();
        $root->add(new MemoryCollection('calendars'));

        $this->expectException(BadRequest::class);

        ($this->method($root))(new Request('DELETE', '/calendars', new Headers(['Depth' => '0'])));
    }

    public function testAskedAsAMethodItRaisesNotFoundWhereThereIsNothing(): void
    {
        $this->expectException(NotFound::class);

        ($this->method($this->tree()))(new Request('DELETE', '/nowhere.txt'));
    }

    private function tree(): MemoryCollection
    {
        return new MemoryCollection('');
    }

    private function method(MemoryCollection $root): Delete
    {
        return new Delete(new Server(new Tree($root)));
    }

    /**
     * @param array<string, string> $headers
     */
    private function delete(
        MemoryCollection $root,
        string $target,
        array $headers = [],
        ?EventEmitter $events = null,
        string $baseUri = '/',
    ): Response {
        $server = new Server(new Tree($root), $events, null, $baseUri);
        $delete = new Delete($server);
        $server->onMethod('DELETE', $delete(...));

        return $server->handle(new Request('DELETE', $target, new Headers($headers)));
    }
}

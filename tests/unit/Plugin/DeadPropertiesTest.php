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

namespace DavServices\Tests\Unit\Plugin;

use DavServices\Dav\Event\AfterCopy;
use DavServices\Dav\Event\AfterMove;
use DavServices\Dav\Event\AfterUnbind;
use DavServices\Dav\Event\PropertiesChanging;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\PropPatchResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Plugin\DeadProperties;
use DavServices\Tests\Unit\Backend\MemoryPropertyStorage;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\StreamFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-PROP-02 (dead properties are kept in an
 * exchangeable backend), R-PROP-03 (any XML, losslessly) and R-PROP-04 (a
 * `DELETE` takes them with it).
 *
 * This is the whole of what a backend needs to become part of the protocol:
 * three listeners on seams that were already there. Nothing in `PROPFIND`,
 * `PROPPATCH` or `DELETE` knows that dead properties exist, and an application
 * that wants none of them registers none.
 *
 * The point of it is what RFC 4918 §3 calls a dead property: one the server
 * does not understand and keeps anyway. A calendar's colour, a client's own
 * bookkeeping. Without somewhere to put them, every client that relies on one
 * finds its settings gone at the next start.
 */
#[CoversClass(DeadProperties::class)]
final class DeadPropertiesTest extends TestCase
{
    public function testAnswersAKeptPropertyThatWasAskedForByName(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        $answers = $this->answers($storage, PropFindForm::Named, ['{DAV:}displayname']);

        self::assertSame(['{DAV:}displayname' => 'Work'], $answers);
    }

    /**
     * `allprop` is the one that matters most here: a dead property nobody has
     * asked for by name can be found in no other way, and a client that sends
     * `allprop` is asking what there is at all.
     */
    public function testAnswersEveryKeptPropertyUnderAllProp(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{http://apple.com/ns/ical/}calendar-color' => '#711A76']);

        $answers = $this->answers($storage, PropFindForm::Everything);

        self::assertSame(['{http://apple.com/ns/ical/}calendar-color' => '#711A76'], $answers);
    }

    /**
     * `propname` asks which properties there are. The names come out of the
     * storage; the values are not even fetched.
     */
    public function testAnswersTheNamesAloneUnderPropName(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        $answers = $this->answers($storage, PropFindForm::NamesOnly);

        self::assertSame(['{DAV:}displayname' => null], $answers);
    }

    /**
     * All of them in one go: a storage asked for one property at a time is a
     * query per property, and on a `Depth: 1` listing one per member as well.
     */
    public function testAnswersEveryPropertyThatWasAskedFor(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work', '{DAV:}owner' => 'Alice']);

        $answers = $this->answers($storage, PropFindForm::Named, ['{DAV:}displayname', '{DAV:}owner']);

        self::assertSame(['{DAV:}displayname' => 'Work', '{DAV:}owner' => 'Alice'], $answers);
    }

    public function testAnswersNothingForAPathItKeepsNothingFor(): void
    {
        $answers = $this->answers(new MemoryPropertyStorage(), PropFindForm::Named, ['{DAV:}displayname']);

        self::assertSame([], $answers);
    }

    /**
     * A property somebody answered first stays answered: the storage is one
     * contributor among several, and the order is the rule (R-PROP-05).
     */
    public function testDoesNotOverruleAnAnswerThatWasAlreadyGiven(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'What the storage keeps']);

        $result = new PropFindResult('work.ics', PropFindForm::Named, ['{DAV:}displayname']);
        $result->set('{DAV:}displayname', 'What a plugin said first');

        (new DeadProperties($storage))->answer(new PropertiesRequested($result, new StreamFile('work.ics')));

        self::assertSame(['{DAV:}displayname' => 'What a plugin said first'], $result->byStatus()[200] ?? []);
    }

    /**
     * The whole way through, which is what an application actually sees: a
     * `PROPPATCH` puts a property in the storage and a `PROPFIND` hands it
     * back.
     */
    public function testKeepsWhatAPropPatchSendsAndHandsItBackToAPropFind(): void
    {
        $storage = new MemoryPropertyStorage();
        $server = $this->server($storage);

        $server->handle($this->propPatch('<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>'));

        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('work.ics'));
        self::assertStringContainsString('<d:displayname>Work</d:displayname>', (string) $server->handle($this->propFind())->body());
    }

    /**
     * R-PROP-03: whatever XML a client invented comes back as it went in.
     */
    public function testKeepsWhateverXmlAClientInvented(): void
    {
        $storage = new MemoryPropertyStorage();

        $this->server($storage)->handle($this->propPatch('
            <D:set><D:prop><X:thing xmlns:X="http://example.com/ns"><X:inner id="7">Deep</X:inner></X:thing></D:prop></D:set>
        '));

        $kept = $storage->properties('work.ics', ['{http://example.com/ns}thing'])['{http://example.com/ns}thing'] ?? null;

        self::assertInstanceOf(Element::class, $kept);
        self::assertSame('{http://example.com/ns}inner', ($kept->children()[0] ?? null)?->name());
    }

    public function testAPropPatchCanRemoveAKeptProperty(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        $this->server($storage)->handle($this->propPatch('<D:remove><D:prop><D:displayname/></D:prop></D:remove>'));

        self::assertSame([], $storage->propertyNames('work.ics'));
    }

    /**
     * R-DAV-05 still holds with a storage in play: a change that is refused
     * elsewhere leaves nothing behind here either, because nothing is written
     * until everything has been agreed to.
     */
    public function testKeepsNothingWhereTheRequestWasRefused(): void
    {
        $storage = new MemoryPropertyStorage();

        $events = new EventEmitter();
        (new DeadProperties($storage))->registerOn($events);
        $events->on(PropertiesChanging::class, static function (PropertiesChanging $event): void {
            $event->result()->set('{DAV:}getetag', 403);
        });

        $this->serverFor($this->tree(), $events)->handle($this->propPatch('
            <D:set><D:prop><D:displayname>Work</D:displayname><D:getetag>"abc"</D:getetag></D:prop></D:set>
        '));

        self::assertSame([], $storage->propertyNames('work.ics'), 'Something was kept although the request failed.');
    }

    /**
     * A node that keeps its own properties is left to keep them. Storing them
     * in a second place would take the node's chance to refuse a change along
     * with them - which is exactly how a `PROPPATCH` stops being atomic.
     */
    public function testANodeThatKeepsItsOwnPropertiesIsLeftToIt(): void
    {
        $storage = new MemoryPropertyStorage();
        $root = (new MemoryCollection(''))->add(new MemoryFile('work.ics'));

        $events = new EventEmitter();
        (new DeadProperties($storage))->registerOn($events);

        $this->serverFor($root, $events)->handle($this->propPatch('<D:set><D:prop><D:displayname>Work</D:displayname></D:prop></D:set>'));

        $file = $root->child('work.ics');

        self::assertInstanceOf(MemoryFile::class, $file);
        self::assertSame(['{DAV:}displayname'], $file->propertyNames());
        self::assertSame([], $storage->propertyNames('work.ics'), 'The storage took what the node keeps itself.');
    }

    /**
     * R-PROP-04: what a `DELETE` removed keeps no properties behind it. They
     * would otherwise reappear on the next resource of that name.
     */
    public function testForgetsThePropertiesOfWhatWasDeleted(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        $root = $this->tree();
        $events = new EventEmitter();
        (new DeadProperties($storage))->registerOn($events);

        $server = $this->serverFor($root, $events);
        $delete = new Delete($server);
        $server->onMethod('DELETE', $delete(...));

        $server->handle(new Request('DELETE', '/work.ics'));

        self::assertSame([], $storage->propertyNames('work.ics'));
    }

    /**
     * A plugin knows which seams it needs; an application should not have to.
     */
    public function testRegistersItselfOnEverySeamItNeeds(): void
    {
        $events = new EventEmitter();
        $storage = new MemoryPropertyStorage();

        (new DeadProperties($storage))->registerOn($events);
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        $result = new PropFindResult('work.ics', PropFindForm::Named, ['{DAV:}displayname']);
        $events->emit(new PropertiesRequested($result, new StreamFile('work.ics')));

        self::assertSame(
            [200 => ['{DAV:}displayname' => 'Work']],
            $result->byStatus(),
            'The listener for a PROPFIND was not registered.',
        );
    }

    /**
     * R-PROP-04 the whole way through: what a `MOVE` carried keeps its
     * properties, and the old path keeps none.
     */
    public function testCarriesThePropertiesOfWhatWasMoved(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        $root = $this->tree();
        $events = new EventEmitter();
        (new DeadProperties($storage))->registerOn($events);

        $server = $this->serverFor($root, $events);
        $move = new Move($server);
        $server->onMethod('MOVE', $move(...));

        $server->handle(new Request('MOVE', '/work.ics', new Headers(['Destination' => '/archive.ics'])));

        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('archive.ics'));
        self::assertSame([], $storage->propertyNames('work.ics'));
    }

    /**
     * RFC 4918 §9.8.2: a `COPY` duplicates them, and the original keeps its
     * own.
     */
    public function testDuplicatesThePropertiesOntoACopy(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        $root = $this->tree();
        $events = new EventEmitter();
        (new DeadProperties($storage))->registerOn($events);

        $server = $this->serverFor($root, $events);
        $copy = new Copy($server);
        $server->onMethod('COPY', $copy(...));

        $server->handle(new Request('COPY', '/work.ics', new Headers(['Destination' => '/archive.ics'])));

        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('archive.ics'));
        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('work.ics'));
    }

    /**
     * Xdebug measures no branch of a listener that is only ever reached through
     * a first-class callable, so each one is asked for directly as well.
     */
    public function testAskedDirectlyItTakesOnWhatTheNodeCannotKeep(): void
    {
        $storage = new MemoryPropertyStorage();
        $result = new PropPatchResult('work.ics', ['{DAV:}displayname' => 'Work']);

        (new DeadProperties($storage))->keep(new PropertiesChanging($result, new StreamFile('work.ics')));

        foreach ($result->writers() as $writer) {
            $writer('Work');
        }

        self::assertSame(['{DAV:}displayname' => 'Work'], $storage->properties('work.ics', ['{DAV:}displayname']));
    }

    public function testAskedDirectlyItLeavesANodeThatKeepsItsOwn(): void
    {
        $result = new PropPatchResult('work.ics', ['{DAV:}displayname' => 'Work']);

        (new DeadProperties(new MemoryPropertyStorage()))->keep(new PropertiesChanging($result, new MemoryFile('work.ics')));

        self::assertSame([], $result->writers());
    }

    public function testAskedDirectlyItForgetsWhatWentAway(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        (new DeadProperties($storage))->forget(new AfterUnbind('work.ics'));

        self::assertSame([], $storage->propertyNames('work.ics'));
    }

    public function testAskedDirectlyItDuplicatesOntoACopy(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        (new DeadProperties($storage))->copy(new AfterCopy('work.ics', 'archive.ics'));

        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('archive.ics'));
    }

    public function testAskedDirectlyItCarriesWhatWasMoved(): void
    {
        $storage = new MemoryPropertyStorage();
        $storage->patchProperties('work.ics', ['{DAV:}displayname' => 'Work']);

        (new DeadProperties($storage))->carry(new AfterMove('work.ics', 'archive.ics'));

        self::assertSame(['{DAV:}displayname'], $storage->propertyNames('archive.ics'));
        self::assertSame([], $storage->propertyNames('work.ics'));
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, Element|string|null>
     */
    private function answers(
        MemoryPropertyStorage $storage,
        PropFindForm $form,
        array $names = [],
    ): array {
        $result = new PropFindResult('work.ics', $form, $names);

        (new DeadProperties($storage))->answer(new PropertiesRequested($result, new StreamFile('work.ics')));

        return $result->byStatus()[200] ?? [];
    }

    /**
     * A file that keeps no properties of its own, which is what this plugin is
     * for: one that keeps its own is left to keep them.
     */
    private function tree(): MemoryCollection
    {
        return (new MemoryCollection(''))->add(new StreamFile('work.ics'));
    }

    private function server(MemoryPropertyStorage $storage): Server
    {
        $events = new EventEmitter();

        (new DeadProperties($storage))->registerOn($events);

        return $this->serverFor($this->tree(), $events);
    }

    private function serverFor(MemoryCollection $root, EventEmitter $events): Server
    {
        $server = new Server(new Tree($root), $events);
        $propFind = new PropFind($server);
        $propPatch = new PropPatch($server);

        $server->onMethod('PROPFIND', $propFind(...));
        $server->onMethod('PROPPATCH', $propPatch(...));

        return $server;
    }

    private function propPatch(string $instructions): Request
    {
        return new Request(
            'PROPPATCH',
            '/work.ics',
            body: new Body(sprintf('<D:propertyupdate xmlns:D="DAV:">%s</D:propertyupdate>', trim($instructions))),
        );
    }

    private function propFind(): Request
    {
        return new Request('PROPFIND', '/work.ics', new Headers(['Depth' => '0']));
    }
}

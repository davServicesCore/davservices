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

namespace DavServices\Tests\Unit\Dav\Property;

use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Property\Answers;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Event\EventEmitter;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\PlainNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-PROP-05, R-DAV-04 and RFC 4918 §9.1.
 *
 * **What this server says about the properties of one resource.** It was
 * private inside `PROPFIND` until `DAV:principal-property-search` needed the
 * same answer: the search compares property values, and values it worked out
 * for itself would be values a client never sees — a second place that would
 * have to be kept in step with the first.
 *
 * Two rules live here and both are load-bearing.
 *
 * **The listeners answer first, the node after them** (R-PROP-05), and the
 * first answer for a property stands. That is how access control refuses a
 * property the node would happily hand over: it takes the first word so that
 * nothing behind it can supply the value the client was not to see. A
 * gathering that asked the node first would defeat that quietly.
 *
 * **The node is only asked what is still open.** A backend asked for what a
 * plugin has already answered runs a query for nothing, and on a listing of
 * two hundred members that is two hundred of them (R-PRIV-01 in spirit).
 */
#[CoversClass(Answers::class)]
final class AnswersTest extends TestCase
{
    private const COLOUR = '{https://dav.services/test}colour';

    private const SHAPE = '{https://dav.services/test}shape';

    public function testHandsOverWhatTheNodeKeeps(): void
    {
        $node = (new MemoryFile('work.ics', ''))->withProperty(self::COLOUR, 'blue');

        $answers = $this->about($node, PropFindForm::Named, [self::COLOUR]);

        self::assertSame(['{https://dav.services/test}colour' => 'blue'], $answers->byStatus()[200] ?? []);
    }

    /**
     * **R-PROP-05: whoever must have the last word takes the first one.** A
     * listener that answered after the node could only correct it, and a
     * correction that arrives late is a value that was already worked out —
     * on a refusal, worked out for somebody who may not see it.
     */
    public function testAListenerAnswersBeforeTheNodeAndKeepsIt(): void
    {
        $node = (new MemoryFile('work.ics', ''))->withProperty(self::COLOUR, 'blue');
        $events = new EventEmitter();

        $events->on(
            PropertiesRequested::class,
            static fn (PropertiesRequested $event) => $event->result()->set(self::COLOUR, 'red'),
        );

        $answers = Answers::about($events, 'work.ics', $node, PropFindForm::Named, [self::COLOUR]);

        self::assertSame(['{https://dav.services/test}colour' => 'red'], $answers->byStatus()[200] ?? []);
    }

    /**
     * And the node is not even asked for it: a property somebody has answered
     * is work the storage should not do.
     */
    public function testTheNodeIsNotAskedForWhatWasAnsweredAlready(): void
    {
        $node = (new MemoryFile('work.ics', ''))
            ->withProperty(self::COLOUR, 'blue')
            ->withProperty(self::SHAPE, 'round');

        $events = new EventEmitter();

        $events->on(
            PropertiesRequested::class,
            static fn (PropertiesRequested $event) => $event->result()->set(self::COLOUR, 'red'),
        );

        Answers::about($events, 'work.ics', $node, PropFindForm::Named, [self::COLOUR, self::SHAPE]);

        self::assertSame([self::SHAPE], $node->askedFor);
    }

    /**
     * RFC 4918 §9.1: `allprop` reports what the resource has, which is the
     * only way a client learns of a dead property — nobody can name what
     * nobody has told them about.
     */
    public function testUnderAllpropTheNodesOwnNamesAreTheQuestion(): void
    {
        $node = (new MemoryFile('work.ics', ''))->withProperty(self::COLOUR, 'blue');

        $answers = $this->about($node, PropFindForm::Everything);

        self::assertArrayHasKey(self::COLOUR, $answers->byStatus()[200] ?? []);
    }

    /**
     * **`propname` asks which properties there are, not what they hold**, so
     * fetching the values would turn the cheap question into the expensive
     * one — on a large collection, for an answer that is thrown away.
     */
    public function testUnderPropnameTheValuesAreNeverFetched(): void
    {
        $node = (new MemoryFile('work.ics', ''))->withProperty(self::COLOUR, 'blue');

        $answers = $this->about($node, PropFindForm::NamesOnly);

        self::assertArrayHasKey(self::COLOUR, $answers->byStatus()[200] ?? []);
        self::assertSame([], $node->askedFor, 'the node was asked for no values at all');
    }

    /**
     * R-DAV-04: a property that was named and that nobody has is a `404` of
     * its own. A client handed three answers to five questions has nothing to
     * tell it which two went missing.
     */
    public function testAPropertyNobodyHasIsAccountedFor(): void
    {
        $answers = $this->about(new MemoryFile('work.ics', ''), PropFindForm::Named, [self::COLOUR]);

        self::assertSame([self::COLOUR => null], $answers->byStatus()[404] ?? []);
    }

    /**
     * **A node that keeps no properties at all is not a special case.** Every
     * resource has live properties, and they come from a listener; a node
     * that does not implement `IProperties` simply has nothing to add.
     */
    public function testANodeThatKeepsNoPropertiesIsAskedForNone(): void
    {
        $events = new EventEmitter();

        $events->on(
            PropertiesRequested::class,
            static fn (PropertiesRequested $event) => $event->result()->set(self::COLOUR, 'blue'),
        );

        $answers = Answers::about($events, 'plain', new PlainNode('plain'), PropFindForm::Named, [self::COLOUR]);

        self::assertSame([self::COLOUR => 'blue'], $answers->byStatus()[200] ?? []);
    }

    /**
     * The result carries the path and the form it was asked in, because a
     * listener decides what to answer from both — access control needs the
     * path to ask whether this one may be read at all.
     */
    public function testTheResultKnowsWhatItIsAbout(): void
    {
        $answers = $this->about(new MemoryFile('work.ics', ''), PropFindForm::Named, [self::COLOUR]);

        self::assertSame('calendars/work.ics', $answers->path());
        self::assertSame(PropFindForm::Named, $answers->form());
    }

    /**
     * @param list<string> $names
     */
    private function about(MemoryFile $node, PropFindForm $form, array $names = []): PropFindResult
    {
        return Answers::about(new EventEmitter(), 'calendars/work.ics', $node, $form, $names);
    }
}

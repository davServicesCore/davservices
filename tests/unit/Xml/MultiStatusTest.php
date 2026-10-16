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

namespace DavServices\Tests\Unit\Xml;

use DavServices\Exception\Forbidden;
use DavServices\Exception\Locked;
use DavServices\Xml\Element;
use DavServices\Xml\MultiStatus;
use DavServices\Xml\Reader;
use DavServices\Xml\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from R-XML-07 and RFC 4918 §14.16 to §14.24.
 *
 * A `207` is the answer to a request that partly worked, and its shape is the
 * part of WebDAV clients get wrong most often — so this builds it in one
 * place, and every method that answers with one goes through here.
 *
 * The nesting that matters: one `response` per resource, each with an `href`;
 * properties grouped into a `propstat` **per status**, never one block with
 * mixed results; and a `status` of its own where the whole resource failed
 * rather than a property of it.
 */
#[CoversClass(MultiStatus::class)]
final class MultiStatusTest extends TestCase
{
    public function testAnEmptyMultiStatusIsStillADocument(): void
    {
        $element = (new MultiStatus())->toElement();

        self::assertSame('{DAV:}multistatus', $element->name());
        self::assertSame([], $element->children());
    }

    public function testWritesAResponseWithItsHref(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/calendars/alice/', [200 => ['{DAV:}displayname' => 'Alice']]);

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertSame('{DAV:}response', $response->name());
        self::assertSame('{DAV:}href', self::childAt($response, 0)->name());
        self::assertSame('/calendars/alice/', self::childAt($response, 0)->text());
    }

    /**
     * RFC 4918 §14.22: the status belongs to the `propstat`, and properties
     * that fared differently belong to different blocks. A client reading one
     * block with a `404` in it has no way to tell which property was missing.
     */
    public function testGroupsThePropertiesByTheirStatus(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [
            200 => ['{DAV:}displayname' => 'Alice'],
            404 => ['{DAV:}getetag' => null],
        ]);

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertSame('{DAV:}propstat', self::childAt($response, 1)->name());
        self::assertSame('{DAV:}propstat', self::childAt($response, 2)->name());
        self::assertSame('HTTP/1.1 200 OK', self::statusOf(self::childAt($response, 1)));
        self::assertSame('HTTP/1.1 404 Not Found', self::statusOf(self::childAt($response, 2)));
    }

    public function testWritesAPropertyThatCarriesText(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [200 => ['{DAV:}displayname' => 'Alice']]);

        $property = self::propertyAt($multiStatus, 0);

        self::assertSame('{DAV:}displayname', $property->name());
        self::assertSame('Alice', $property->text());
        self::assertSame([], $property->children());
    }

    /**
     * A property may hold any XML at all (R-PROP-03): `resourcetype` holds
     * elements, and a dead property holds whatever was put there.
     */
    public function testWritesAPropertyThatCarriesXml(): void
    {
        $resourceType = new Element('{DAV:}resourcetype');
        $resourceType->append(new Element('{DAV:}collection'));

        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [200 => ['{DAV:}resourcetype' => $resourceType]]);

        $property = self::propertyAt($multiStatus, 0);

        self::assertSame('{DAV:}resourcetype', $property->name());
        self::assertSame('{DAV:}collection', self::childAt($property, 0)->name());
    }

    /**
     * A property that is absent is named and left empty — that is what a `404`
     * block is for, and what `propname` asks for.
     */
    public function testWritesAPropertyThatIsOnlyNamed(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [404 => ['{DAV:}getetag' => null]]);

        $property = self::propertyAt($multiStatus, 0);

        self::assertSame('{DAV:}getetag', $property->name());
        self::assertSame('', $property->text());
        self::assertSame([], $property->children());
    }

    /**
     * **A property that names a condition gets a `propstat` of its own.**
     * RFC 4918 §16 puts the condition "inside an 'error' element in the
     * appropriate 'propstat'", and §14.22 gives a `propstat` one `error?` —
     * so two reasons are two blocks. A `propstat` holds the properties that
     * fared identically, and a different reason is a different fate even at
     * the same status.
     */
    public function testAPropertyThatNamesAConditionGetsItsOwnBlock(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties(
            '/calendars/work/',
            [403 => ['{DAV:}displayname' => null, '{DAV:}getetag' => null]],
            errors: ['{DAV:}getetag' => '{DAV:}cannot-modify-protected-property'],
        );

        $response = self::childAt($multiStatus->toElement(), 0);
        $plain = self::childAt($response, 1);
        $named = self::childAt($response, 2);

        self::assertSame(
            ['{DAV:}displayname'],
            self::childAt($plain, 0)->childNames(),
            'The two fates were put in one block.',
        );
        self::assertSame(['{DAV:}getetag'], self::childAt($named, 0)->childNames());
        self::assertSame('{DAV:}status', self::childAt($named, 1)->name());

        $error = self::childAt($named, 2);

        self::assertSame('{DAV:}error', $error->name());
        self::assertSame('{DAV:}cannot-modify-protected-property', self::childAt($error, 0)->name());
    }

    /**
     * **A status that carries no property at all is still a block.**
     * RFC 4918 §14.24 has every `response` hold at least one `propstat`, and
     * §9.1's `DAV:propname` is where this arises: a resource with nothing to
     * say says so with an empty `prop`, and a document without the block is
     * one a strict client is right to refuse.
     *
     * {@see \DavServices\Dav\PropFindResult::byStatus()} is what produces the
     * shape, and until now only its own test knew about it — which left the
     * writer's half of the rule resting on nothing.
     */
    public function testAStatusWithoutAPropertyIsStillABlock(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [200 => []]);

        $response = self::childAt($multiStatus->toElement(), 0);
        $propStat = self::childAt($response, 1);

        self::assertSame('{DAV:}propstat', $propStat->name());
        self::assertSame([], self::childAt($propStat, 0)->children());
        self::assertSame('HTTP/1.1 200 OK', self::childAt($propStat, 1)->text());
    }

    /**
     * **Two properties refused for the same reason are one block**, which is
     * what a `propstat` is: RFC 4918 §14.22 reads it as the properties that
     * fared identically, and two refusals with one condition between them did.
     */
    public function testTwoPropertiesWithOneConditionShareABlock(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties(
            '/x',
            [403 => ['{DAV:}getetag' => null, '{DAV:}getlastmodified' => null]],
            errors: [
                '{DAV:}getetag' => '{DAV:}cannot-modify-protected-property',
                '{DAV:}getlastmodified' => '{DAV:}cannot-modify-protected-property',
            ],
        );

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertCount(2, $response->children(), 'An href and one propstat make two.');
        self::assertSame(
            ['{DAV:}getetag', '{DAV:}getlastmodified'],
            self::childAt(self::childAt($response, 1), 0)->childNames(),
        );
    }

    /**
     * **Two different conditions are two blocks**, which is the half that
     * makes the grouping worth anything: a block holds what fared identically,
     * and two properties refused for different reasons did not.
     */
    public function testTwoDifferentConditionsAreTwoBlocks(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties(
            '/x',
            [403 => ['{DAV:}getetag' => null, '{DAV:}displayname' => null]],
            errors: [
                '{DAV:}getetag' => '{DAV:}cannot-modify-protected-property',
                '{DAV:}displayname' => '{DAV:}preserved-live-properties',
            ],
        );

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertCount(3, $response->children(), 'The two reasons were put in one block.');
        self::assertSame(['{DAV:}getetag'], self::childAt(self::childAt($response, 1), 0)->childNames());
        self::assertSame(['{DAV:}displayname'], self::childAt(self::childAt($response, 2), 0)->childNames());
    }

    /**
     * **And a condition that carries detail is told apart by what it is**, not
     * by its name: §16 lets one hold "child elements with specific error
     * information", so two elements of the same name may say different things
     * and belong in blocks of their own.
     */
    public function testTwoConditionsThatCarryDetailAreTwoBlocks(): void
    {
        $taken = new Element('{DAV:}no-uid-conflict');
        $other = new Element('{DAV:}no-uid-conflict');

        $taken->append(new Element('{DAV:}href'));

        $multiStatus = new MultiStatus();
        $multiStatus->addProperties(
            '/x',
            [409 => ['{DAV:}getetag' => null, '{DAV:}getlastmodified' => null]],
            errors: ['{DAV:}getetag' => $taken, '{DAV:}getlastmodified' => $other],
        );

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertCount(3, $response->children(), 'The two conditions were put in one block.');
        self::assertSame($taken, self::childAt(self::childAt($response, 1), 2)->children()[0] ?? null);
    }

    /**
     * And properties that name nothing stay in the one block they were in: the
     * split is paid for only where there is a reason to split.
     */
    public function testPropertiesThatNameNoConditionStayInOneBlock(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [403 => ['{DAV:}displayname' => null, '{DAV:}getetag' => null]]);

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertCount(2, $response->children(), 'An href and one propstat make two.');
        self::assertSame(
            ['{DAV:}displayname', '{DAV:}getetag'],
            self::childAt(self::childAt($response, 1), 0)->childNames(),
        );
    }

    /**
     * Where the whole resource failed, the response carries the status itself
     * rather than a `propstat` — a `DELETE` that could not remove one member
     * of a collection answers like this (RFC 4918 §9.6.1).
     */
    public function testWritesAResponseThatFailedAsAWhole(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/x/locked.txt', 423);

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertSame('{DAV:}href', self::childAt($response, 0)->name());
        self::assertSame('{DAV:}status', self::childAt($response, 1)->name());
        self::assertSame('HTTP/1.1 423 Locked', self::childAt($response, 1)->text());
    }

    public function testWritesThePreconditionThatWasNotMet(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/x', 423, '{DAV:}lock-token-submitted');

        $response = self::childAt($multiStatus->toElement(), 0);
        $error = self::childAt($response, 2);

        self::assertSame('{DAV:}error', $error->name());
        self::assertSame('{DAV:}lock-token-submitted', self::childAt($error, 0)->name());
    }

    public function testWritesAWordOfExplanation(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/x', 507, null, 'The quota of the collection is exhausted.');

        $response = self::childAt($multiStatus->toElement(), 0);
        $description = self::childAt($response, 2);

        self::assertSame('{DAV:}responsedescription', $description->name());
        self::assertSame('The quota of the collection is exhausted.', $description->text());
    }

    /**
     * A word of explanation belongs to a response whose properties were asked
     * for just as much as to one that failed as a whole — a `403` on one
     * property is where a client most wants to be told why.
     */
    public function testWritesAWordOfExplanationBesideProperties(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/x', [403 => ['{DAV:}owner' => null]], 'The owner may not be set here.');

        $response = self::childAt($multiStatus->toElement(), 0);
        $description = self::childAt($response, 2);

        self::assertSame('{DAV:}responsedescription', $description->name());
        self::assertSame('The owner may not be set here.', $description->text());
    }

    /**
     * RFC 9112 §4 allows a status line with no reason phrase, and a status
     * this library has no phrase for gets none — but the line must not go out
     * with the trailing space that would leave behind.
     */
    public function testAStatusWithoutAPhraseLeavesNoTrailingSpace(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/x', 299);

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertSame('HTTP/1.1 299', self::childAt($response, 1)->text());
    }

    public function testKeepsTheResponsesInTheOrderTheyWereAdded(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addStatus('/first', 404);
        $multiStatus->addStatus('/second', 423);
        $multiStatus->addProperties('/third', [200 => ['{DAV:}getetag' => '"abc"']]);

        $hrefs = array_map(
            static fn (Element $response): string => self::childAt($response, 0)->text(),
            $multiStatus->toElement()->children(),
        );

        self::assertSame(['/first', '/second', '/third'], $hrefs);
    }

    /**
     * **One `response` on its own.** RFC 3253 §3.8 nests a whole response
     * *inside a property value*: `DAV:expand-property` replaces every
     * `DAV:href` in a value with the response of the resource it names.
     *
     * So the shape has to be available without a document around it — and
     * from here, rather than built a second time by the report, because the
     * nesting of §14.24 is the part implementations get wrong most often and
     * a second copy is a second chance to get it wrong.
     */
    public function testBuildsOneResponseWithoutADocumentAroundIt(): void
    {
        $response = MultiStatus::responseWith('/principals/alice', [
            200 => ['{DAV:}displayname' => 'Alice'],
            404 => ['{DAV:}getctag' => null],
        ]);

        self::assertSame('{DAV:}response', $response->name());
        self::assertSame('/principals/alice', self::childAt($response, 0)->text());
        self::assertSame('{DAV:}propstat', self::childAt($response, 1)->name());
        self::assertSame('{DAV:}propstat', self::childAt($response, 2)->name());
    }

    /**
     * And it is the very same shape the document uses, which is the point of
     * having one place: a response built on its own and one inside a
     * multistatus differ in nothing.
     */
    public function testAResponseOnItsOwnIsTheSameShapeAsOneInTheDocument(): void
    {
        $multiStatus = new MultiStatus();

        $multiStatus->addProperties('/principals/alice', [200 => ['{DAV:}displayname' => 'Alice']]);

        $inside = (new Writer())->write(self::childAt($multiStatus->toElement(), 0));
        $alone = (new Writer())->write(MultiStatus::responseWith('/principals/alice', [
            200 => ['{DAV:}displayname' => 'Alice'],
        ]));

        self::assertSame($inside, $alone);
    }

    /**
     * The document a client actually receives, read back with this library's
     * own reader: the shape of RFC 4918 §14.24 end to end.
     */
    public function testTheWrittenDocumentReadsBackAsItWasBuilt(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addProperties('/calendars/alice/', [
            200 => ['{DAV:}displayname' => 'Alice & friends'],
            404 => ['{DAV:}getctag' => null],
        ]);

        $read = (new Reader())->parse((new Writer())->write($multiStatus->toElement()));
        $response = self::childAt($read, 0);

        self::assertSame('{DAV:}multistatus', $read->name());
        self::assertSame('/calendars/alice/', self::childAt($response, 0)->text());
        self::assertSame('Alice & friends', self::childAt(self::childAt(self::childAt($response, 1), 0), 0)->text());
        self::assertSame('HTTP/1.1 404 Not Found', self::statusOf(self::childAt($response, 2)));
    }

    private static function statusOf(Element $propstat): string
    {
        foreach ($propstat->children() as $child) {
            if ($child->name() === '{DAV:}status') {
                return $child->text();
            }
        }

        self::fail('The propstat carries no status.');
    }

    private static function propertyAt(MultiStatus $multiStatus, int $index): Element
    {
        $response = self::childAt($multiStatus->toElement(), 0);
        $prop = self::childAt(self::childAt($response, 1), 0);

        self::assertSame('{DAV:}prop', $prop->name());

        return self::childAt($prop, $index);
    }

    /**
     * Every method that reports a failure per path does it the same way, so
     * the way is here: the status, the precondition where the refusal names
     * one, and the message where there is one to read.
     */
    public function testWritesARefusalAsTheRefusalDescribesItself(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addFailure('/calendars/work.ics', new Locked('It is locked.', '{DAV:}lock-token-submitted'));

        $response = self::childAt($multiStatus->toElement(), 0);

        self::assertSame('HTTP/1.1 423 Locked', self::childAt($response, 1)->text());
        self::assertSame('{DAV:}lock-token-submitted', self::childAt(self::childAt($response, 2), 0)->name());
        self::assertSame('It is locked.', self::childAt($response, 3)->text());
    }

    /**
     * A refusal that came without a message says nothing rather than saying
     * nothing at length.
     */
    public function testLeavesOutAnEmptyReason(): void
    {
        $multiStatus = new MultiStatus();
        $multiStatus->addFailure('/calendars/work.ics', new Forbidden());

        self::assertCount(2, self::childAt($multiStatus->toElement(), 0)->children());
    }

    private static function childAt(Element $element, int $index): Element
    {
        $child = $element->children()[$index] ?? null;

        if ($child === null) {
            self::fail(sprintf('The element holds no child at %d.', $index));
        }

        return $child;
    }
}

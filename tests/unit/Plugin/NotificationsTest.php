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

use DavServices\Acl\Principal;
use DavServices\CalDav\NotificationCollection;
use DavServices\Dav\Acl\PrincipalInfo;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Notifications;
use DavServices\Tests\Unit\CalDav\MemoryNotification;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Tests\Unit\Dav\MemoryPropertyCollection;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list for the notification plugin. R-CAL-21, P7-06.
 *
 * **No RFC defines any of this.** The source is `caldav-sharing`, "Shared and
 * Published Calendars in CalDAV", C. Daboo and E. York, Apple, 19 September
 * 2012, which `docs/referenzen/download-extensions.sh` fetches. §4 of it
 * defines the mechanism and this plugin answers its two properties.
 *
 * ## `CS:notification-URL`, §4.1.1
 *
 * > Purpose: Identify the URL of the notification collection owned by the
 * > associated principal resource.
 * >
 * > Protected: This property SHOULD be protected.
 * >
 * > PROPFIND behavior: This property SHOULD NOT be returned by a PROPFIND
 * > allprop request.
 * >
 * > Description: […] If not present, then the associated calendar user is not
 * > enabled for notification messages on the server.
 * >
 * > <!ELEMENT notification-URL (DAV:href)>
 *
 * **That last sentence is why the tree is asked rather than assumed.** A
 * property that named a collection which is not there would be a promise this
 * server cannot keep, and a client would poll a `404` for ever.
 *
 * ## `CS:notificationtype`, §4.2.1
 *
 * > Protected: This property MUST be protected.
 * >
 * > Description: […] The single child element is the notification resource
 * > root element's child defining the notification itself. This element MUST
 * > be empty, though any attributes on the element in the notification
 * > resource MUST be present in the property element.
 *
 * Two rules in one sentence, and they pull against each other: empty the
 * element, keep its attributes.
 */
#[CoversClass(Notifications::class)]
final class NotificationsTest extends TestCase
{
    private const NS = 'http://calendarserver.org/ns/';

    private const INVITE = '{' . self::NS . '}invite-notification';

    /**
     * **§4.1.1: the URL of the principal's notification collection**, as a
     * `DAV:href` inside the property, and the collection named with its
     * trailing slash (RFC 4918 §5.2: "Wherever a server produces a URL
     * referring to a collection, the server SHOULD include the trailing
     * slash").
     */
    public function testAPrincipalWithANotificationCollectionCarriesItsUrl(): void
    {
        $answer = (string) $this->propFind('<T:notification-URL/>', '/principals/alice')->body();

        self::assertStringContainsString('notification-URL', $answer);
        self::assertStringContainsString('<d:href>/notifications/alice/</d:href>', $answer);
        self::assertStringNotContainsString('404 Not Found', $answer);
    }

    /**
     * **And a principal with none carries no property**, which is §4.1.1's own
     * sentence: "If not present, then the associated calendar user is not
     * enabled for notification messages on the server."
     *
     * So the answer is the tree's and not a pattern's. A server that built the
     * URL from the principal's name regardless would send every client to a
     * `404`, and §4's "Clients SHOULD monitor the notification collection"
     * would have them do it for ever.
     */
    public function testAPrincipalWithoutOneCarriesNoUrl(): void
    {
        $answer = (string) $this->propFind('<T:notification-URL/>', '/principals/bob')->body();

        self::assertStringContainsString('404 Not Found', $answer);
    }

    /**
     * And something that is no principal carries none either: §4.1.1 has the
     * property on "principal resources for those principals who are able to
     * receive notifications".
     */
    public function testSomethingThatIsNoPrincipalCarriesNoUrl(): void
    {
        $answer = (string) $this->propFind('<T:notification-URL/>', '/notifications/alice')->body();

        self::assertStringContainsString('404 Not Found', $answer);
    }

    /**
     * **§4.2.1: a notification says what kind it is.** The single child of the
     * property is the notification document's own type element.
     */
    public function testANotificationCarriesItsType(): void
    {
        $answer = (string) $this->propFind(
            '<T:notificationtype/>',
            '/notifications/alice/one.xml',
        )->body();

        self::assertStringContainsString('notificationtype', $answer);
        self::assertStringContainsString('invite-notification', $answer);
        self::assertStringNotContainsString('404 Not Found', $answer);
    }

    /**
     * **The sentence that pulls both ways:** "This element MUST be empty,
     * though any attributes on the element in the notification resource MUST
     * be present in the property element."
     *
     * So the type element arrives as it stands in the document — with its
     * children and its attributes — and goes out stripped of the one and
     * keeping the other.
     */
    public function testTheTypeElementIsEmptiedAndItsAttributesAreKept(): void
    {
        $answer = (string) $this->propFind(
            '<T:notificationtype/>',
            '/notifications/alice/two.xml',
        )->body();

        self::assertStringContainsString('shared-type="calendar"', $answer);
        self::assertStringNotContainsString('<d:href>/calendars/alice/work/</d:href>', $answer);
        self::assertStringNotContainsString('uid', $answer);
    }

    /**
     * And something that is no notification carries no type: a file in an
     * ordinary collection is not a notification message, and §4 has the
     * property on "notification resources".
     */
    public function testSomethingThatIsNoNotificationCarriesNoType(): void
    {
        $answer = (string) $this->propFind('<T:notificationtype/>', '/elsewhere/plain.txt')->body();

        self::assertStringContainsString('404 Not Found', $answer);
    }

    /**
     * **Neither is returned for `allprop`**, both sections saying so in the
     * same words: "This property SHOULD NOT be returned by a PROPFIND allprop
     * request."
     *
     * @param string $path The resource asked about
     * @param string $property The property that must not appear
     */
    #[DataProvider('theTwoPropertiesAndWhereTheyLive')]
    public function testNeitherIsReturnedForAllprop(string $path, string $property): void
    {
        $answer = (string) $this->propFindWhole('<D:allprop/>', $path)->body();

        self::assertStringNotContainsString($property, $answer);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theTwoPropertiesAndWhereTheyLive(): iterable
    {
        yield 'the URL on a principal' => ['/principals/alice', 'notification-URL'];

        yield 'the type on a notification' => ['/notifications/alice/one.xml', 'notificationtype'];
    }

    /**
     * **Both are protected**, §4.1.1 with a SHOULD and §4.2.1 with a MUST, and
     * both for the same reason: the server works them out. A client that could
     * write the type could make a notification it cannot handle look like one
     * it can.
     *
     * @param string $path The resource asked about
     * @param string $set What the `PROPPATCH` tries to set
     */
    #[DataProvider('theTwoPropertiesAndWhatWritingThemLooksLike')]
    public function testBothAreProtected(string $path, string $set): void
    {
        $answer = (string) $this->propPatch($set, $path)->body();

        self::assertStringContainsString('cannot-modify-protected-property', $answer);

        // **And with the status that goes with it**, RFC 4918 §16 having
        // the condition answer a `403`. The name alone would leave a
        // client told it may not write without being told it was refused.
        self::assertStringContainsString('<d:status>HTTP/1.1 403 Forbidden</d:status>', $answer);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theTwoPropertiesAndWhatWritingThemLooksLike(): iterable
    {
        yield 'the URL' => ['/principals/alice', '<T:notification-URL><D:href>/mine/</D:href></T:notification-URL>'];

        yield 'the type' => ['/notifications/alice/one.xml', '<T:notificationtype><T:invite-reply/></T:notificationtype>'];
    }

    /**
     * **And nothing else is protected here.** Without this the plugin could
     * refuse every `PROPPATCH` and still pass every case above — the lesson
     * P7-04's mutation round taught, where a guard over two names negated into
     * one that was true for all of them.
     */
    public function testNoOtherPropertyIsProtectedHere(): void
    {
        $answer = (string) $this->propPatch('<D:displayname>Alerts</D:displayname>', '/notifications/alice')->body();

        self::assertStringNotContainsString('cannot-modify-protected-property', $answer);
        self::assertStringContainsString('200 OK', $answer);
    }

    /**
     * **It announces no sharing support, and that is deliberate.** §5.1: "A
     * server that supports the features described in this document MUST
     * include 'calendarserver-sharing' as a field in the DAV response header
     * from an OPTIONS request on any resource that supports these features."
     *
     * The features described in that document are §4's notifications **and**
     * §5's sharing, and only the first is here. A server announcing the token
     * would have a client `POST` a `CS:share` and be told the method is not
     * allowed — worse than silence, because §5.1 is how a client decides
     * whether to offer sharing at all.
     *
     * **R-CAL-20 has since arrived and this case still stands**: the token is
     * said by {@see \DavServices\Plugin\Sharing}, and only where that plugin
     * was given a `POST` to hang §5.3.2 on. A server with notifications and no
     * sharing is exactly the server this case describes.
     */
    public function testItAnnouncesNoSharingSupport(): void
    {
        $server = $this->server();

        (new Notifications($server))->register();

        $server->onMethod('OPTIONS', (new Options($server))(...));

        $answer = $server->handle(new Request('OPTIONS', '/notifications/alice'));

        self::assertStringNotContainsString(
            'calendarserver-sharing',
            (string) $answer->headers()->first('DAV'),
        );
    }

    /**
     * A `PROPFIND` naming properties in both namespaces.
     */
    private function propFind(string $properties, string $path): Response
    {
        return $this->propFindWhole('<D:prop>' . $properties . '</D:prop>', $path);
    }

    private function propFindWhole(string $body, string $path): Response
    {
        $server = $this->server();

        (new Notifications($server))->register();

        return $server->handle(new Request(
            'PROPFIND',
            $path,
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf(
                '<D:propfind xmlns:D="DAV:" xmlns:T="%s">%s</D:propfind>',
                self::NS,
                $body,
            )),
        ));
    }

    private function propPatch(string $set, string $path): Response
    {
        $server = $this->server();

        (new Notifications($server))->register();

        return $server->handle(new Request(
            'PROPPATCH',
            $path,
            headers: new Headers(['Content-Type' => 'application/xml']),
            body: new Body(sprintf(
                '<D:propertyupdate xmlns:D="DAV:" xmlns:T="%s"><D:set><D:prop>%s</D:prop></D:set>'
                . '</D:propertyupdate>',
                self::NS,
                $set,
            )),
        ));
    }

    /**
     * Two principals — one with a notification collection and one without —
     * the collections themselves, and a file that is no notification at all.
     */
    private function server(): Server
    {
        $principals = (new MemoryCollection('principals'))
            ->add(new Principal(new PrincipalInfo('alice', 'Alice')))
            ->add(new Principal(new PrincipalInfo('bob', 'Bob')));

        $invite = new Element(self::INVITE, ['shared-type' => 'calendar']);
        $invite->append(new Element('{' . self::NS . '}uid'));

        $alice = NotificationCollection::of(
            (new MemoryPropertyCollection('alice'))
                ->add(new MemoryNotification('one.xml', new Element(self::INVITE)))
                ->add(new MemoryNotification('two.xml', $invite)),
        );

        $notifications = (new MemoryCollection('notifications'))->add($alice);
        $elsewhere = (new MemoryCollection('elsewhere'))->add(new MemoryFile('plain.txt', 'nothing'));

        $events = new EventEmitter();

        $events->on(PropertiesRequested::class, (new LiveProperties())(...));

        $server = new Server(
            new Tree(
                (new MemoryCollection(''))
                    ->add($principals)
                    ->add($notifications)
                    ->add($elsewhere),
            ),
            $events,
        );

        $server->onMethod('PROPFIND', (new PropFind($server))(...));
        $server->onMethod('PROPPATCH', (new PropPatch($server))(...));

        return $server;
    }
}

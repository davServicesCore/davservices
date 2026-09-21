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

use DavServices\Acl\Privilege;
use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\Event\CurrentPrincipalRequested;
use DavServices\Dav\Event\OptionsRequested;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Plugin\Acl;
use DavServices\Tests\Unit\Acl\ArrayPrivilegeResolver;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use DavServices\Xml\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §5 and R-ACL-01.
 *
 * **Here everything built since P3-06 reaches a client for the first time.**
 * The principals are resources, the privileges are a tree, the resolver knows
 * who may do what — and these five properties are how a client is told.
 *
 * Four of them are worth stating plainly, because each is easy to get wrong
 * in a way that looks right:
 *
 * **`DAV:supported-privilege-set` is nested, not flat** (§5.3). It is the
 * tree, written as a tree, with a description a person can read and the
 * language that description is in — the DTD requires both. A flat list would
 * tell a client that `DAV:write` and `DAV:bind` are unrelated, which is the
 * one thing the tree exists to say they are not.
 *
 * **`DAV:current-user-privilege-set` is what the asker may do here** (§5.4),
 * aggregates and all: a client uses it to grey out the buttons it must not
 * offer, so reporting only what was granted by name would hide half of it.
 *
 * **`DAV:acl` is the other direction** (§5.5): who holds something here, as
 * the entries somebody wrote rather than as what they come to.
 *
 * **`DAV:inherited-acl-set` is empty rather than missing** (§5.7). Nothing in
 * this library inherits an access control list, and an empty element says so;
 * a `404` would say the server does not know the question.
 *
 * `DAV:owner` and `DAV:group` are deliberately **not** answered here. Who
 * owns a resource is the backend's to say, through `IProperties`, the same
 * way `DAV:displayname` is — a plugin that invented an owner would be
 * inventing a fact about somebody's data.
 */
#[CoversClass(Acl::class)]
final class AclTest extends TestCase
{
    private const ALICE = 'principals/alice';

    /**
     * RFC 3744 §5.3: the tree, written as a tree.
     */
    public function testWritesTheSupportedPrivilegesAsATree(): void
    {
        $body = $this->propFind('/calendars/work', 'supported-privilege-set');

        self::assertStringContainsString(
            '<d:supported-privilege><d:privilege><d:all/></d:privilege>',
            $body,
        );
        self::assertStringContainsString(
            '<d:supported-privilege><d:privilege><d:write-content/></d:privilege>',
            $body,
        );
    }

    /**
     * **And nested inside the one that aggregates it.** A flat list would
     * tell a client that `DAV:write` and `DAV:write-content` are unrelated —
     * the one thing the tree exists to say they are not.
     */
    public function testNestsAPrivilegeInsideTheOneThatAggregatesIt(): void
    {
        $body = $this->propFind('/calendars/work', 'supported-privilege-set');
        $write = strpos($body, '<d:write/>');
        $content = strpos($body, '<d:write-content/>');
        $unlock = strpos($body, '<d:unlock/>');

        self::assertIsInt($write);
        self::assertIsInt($content);
        self::assertIsInt($unlock);
        self::assertLessThan($content, $write, 'write is written before what it aggregates');
        self::assertLessThan($unlock, $content, 'and what it aggregates is written before its next sibling');
    }

    /**
     * The DTD requires a description and requires it to say what language it
     * is in. It is the privilege's own text, so an extension that brings a
     * privilege brings its sentence with it.
     */
    public function testSaysWhatEachPrivilegeIsForAndInWhichLanguage(): void
    {
        $body = $this->propFind('/calendars/work', 'supported-privilege-set');

        self::assertStringContainsString(
            '<d:description xml:lang="en">create a member in this collection</d:description>',
            $body,
        );
    }

    /**
     * RFC 3744 §5.3: a privilege that may not be put in an entry says so.
     * Nothing in the standard tree is, so this is asked of one that is.
     */
    public function testSaysWhenAPrivilegeMayNotBeGrantedDirectly(): void
    {
        $tree = Privilege::standard()->with(
            '{DAV:}read',
            new Privilege('{https://dav.services/test}abstract-one', 'not to be granted', isAbstract: true),
        );

        $body = $this->propFind('/calendars/work', 'supported-privilege-set', privileges: $tree);

        // The flag follows **that** privilege, not some other one: the
        // prefix the writer picks for a foreign namespace is its own
        // business, but where the element sits is not.
        self::assertStringContainsString('abstract-one/></d:privilege><d:abstract/>', $body);
    }

    /**
     * **And one that may be granted does not say it.** A server that marked
     * every privilege abstract would tell a client it can grant none of
     * them, while looking as though it had answered the question.
     */
    public function testSaysNothingOfTheSortAboutAPrivilegeThatMayBeGranted(): void
    {
        $body = $this->propFind('/calendars/work', 'supported-privilege-set');

        self::assertStringNotContainsString('<d:abstract/>', $body);
    }

    /**
     * RFC 3744 §5.4: what the asker may do here, **aggregates and all**. A
     * client greys out the buttons it must not offer from this, so reporting
     * only what was granted by name would hide half of what it may do.
     */
    public function testTellsTheAskerWhatTheyMayDoHere(): void
    {
        $body = $this->propFind('/calendars/work', 'current-user-privilege-set', who: self::ALICE);

        self::assertStringContainsString('<d:privilege><d:write/></d:privilege>', $body);
        self::assertStringContainsString('<d:privilege><d:bind/></d:privilege>', $body);
    }

    /**
     * **And nothing more than that.** Reporting what somebody may do is not
     * an invitation to round it up: a client shows the buttons this property
     * names, so a privilege reported in error is a button that fails.
     *
     * Worth noting what this test cannot be: an *empty* set is unobservable
     * through the protocol once the checks of R-ACL-05 are on, because
     * reading the property needs `DAV:read` and `DAV:read` is itself in the
     * set. Somebody who holds nothing is refused the `PROPFIND`, and the
     * empty answer is asked for directly instead — see
     * {@see self::testNobodyMayDoAnythingWhereThereIsNoRequestAtAll()}.
     */
    public function testTellsTheAskerOnlyWhatTheyMayDo(): void
    {
        $body = $this->propFind('/calendars/work', 'current-user-privilege-set', who: 'principals/bob');

        self::assertStringContainsString('<d:privilege><d:read/></d:privilege>', $body);
        self::assertStringNotContainsString('<d:write/>', $body);
    }

    /**
     * RFC 3744 §5.5: who holds something here — **as the entries somebody
     * wrote**, not as what they come to. An administrator reading this is
     * reading their own list back.
     */
    public function testSaysWhoHoldsSomethingHere(): void
    {
        $body = $this->propFind('/calendars/work', 'acl', who: self::ALICE);

        self::assertStringContainsString('<d:href>/principals/alice</d:href>', $body);
        self::assertStringContainsString('<d:href>/principals/bob</d:href>', $body);
        self::assertStringContainsString('<d:grant><d:privilege><d:read/></d:privilege>', $body);
    }

    /**
     * And what was granted by name is what is written: `DAV:write` stays
     * `DAV:write` rather than becoming its four.
     */
    public function testWritesTheEntriesAsTheyWereGranted(): void
    {
        $body = $this->propFind('/calendars/work', 'acl', who: self::ALICE);

        self::assertStringContainsString('<d:privilege><d:write/></d:privilege>', $body);
        self::assertStringNotContainsString('<d:privilege><d:write-content/></d:privilege>', $body);
    }

    /**
     * A resource nobody holds anything on has an **empty** access control
     * list, which is a different statement from having none.
     */
    public function testAResourceNobodyHoldsHasAnEmptyList(): void
    {
        $result = new PropFindResult('calendars/private', PropFindForm::Named, ['{DAV:}acl']);

        $this->plugin()->describe(new PropertiesRequested($result, new MemoryFile('private', '')));

        $list = $result->byStatus()[200]['{DAV:}acl'] ?? null;

        self::assertInstanceOf(Element::class, $list);
        self::assertSame([], $list->children());
    }

    /**
     * RFC 3744 §5.6: what this server would refuse in an access control
     * list. **It only ever grants** — there is no denial in this model — and
     * it does not invert a principal. Saying so is a true statement about
     * this library rather than a guess.
     */
    public function testSaysWhatItWouldRefuseInAList(): void
    {
        $body = $this->propFind('/calendars/work', 'acl-restrictions');

        self::assertStringContainsString('<d:acl-restrictions><d:grant-only/><d:no-invert/></d:acl-restrictions>', $body);
    }

    /**
     * RFC 3744 §5.7: **empty rather than missing.** Nothing in this library
     * inherits an access control list, and an empty element says that; a
     * `404` would say the server does not know the question.
     */
    public function testSaysThatNothingInheritsAList(): void
    {
        $body = $this->propFind('/calendars/work', 'inherited-acl-set');

        self::assertStringContainsString('<d:inherited-acl-set/>', $body);
    }

    /**
     * Without the plugin none of it is answered, and the server is a plain
     * WebDAV server (R-ARC-02).
     */
    public function testAServerWithoutThePluginAnswersNoneOfIt(): void
    {
        $server = new Server(new Tree($this->tree()));
        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        self::assertStringContainsString('404 Not Found', (string) $this->ask($server, '/calendars/work', 'acl')->body());
    }

    /**
     * **Nothing is worked out that nobody asked for.** Every one of these
     * costs a question to the resolver, and a `PROPFIND` of two hundred
     * members would ask two hundred times for nothing.
     */
    public function testWorksOutNothingWhereNothingWasAskedFor(): void
    {
        $resolver = ArrayPrivilegeResolver::asTheContractExpects();
        $result = new PropFindResult('calendars/work', PropFindForm::Named, ['{DAV:}getetag']);

        $this->plugin($resolver)->describe(new PropertiesRequested($result, new MemoryFile('work', '')));

        self::assertSame([], $result->byStatus()[200] ?? []);
        self::assertSame(0, $resolver->lookups);
    }

    /**
     * Each of the five asked directly, so that Xdebug sees the decisions
     * every test takes.
     */
    public function testAnswersAllOfThemWhenAskedDirectly(): void
    {
        $result = new PropFindResult('calendars/work', PropFindForm::Named, [
            '{DAV:}supported-privilege-set',
            '{DAV:}current-user-privilege-set',
            '{DAV:}acl',
            '{DAV:}acl-restrictions',
            '{DAV:}inherited-acl-set',
        ]);

        $this->plugin()->describe(new PropertiesRequested($result, new MemoryFile('work', '')));

        self::assertSame(
            [
                '{DAV:}supported-privilege-set',
                '{DAV:}current-user-privilege-set',
                '{DAV:}acl',
                '{DAV:}acl-restrictions',
                '{DAV:}inherited-acl-set',
            ],
            array_keys($result->byStatus()[200] ?? []),
        );
    }

    /**
     * RFC 3744 §5.1, asked of the plugin itself: it is what makes the server
     * of class 3, and `OPTIONS` is only where that gets written down.
     */
    public function testAddsTheThirdComplianceClassToAnOptionsAnswer(): void
    {
        $event = new OptionsRequested(new Request('OPTIONS', '/'));

        $this->plugin()->announce($event);

        self::assertSame(['3', 'access-control'], $event->compliance());
    }

    /**
     * And through a registered server, because a plugin that announces only
     * when asked directly announces nothing at all. `Plugin\Principals` says
     * the same class, so this is asked of a server that has **only** this
     * one.
     */
    public function testAServerWithThisPluginSaysItIsOfClassThree(): void
    {
        $server = new Server(new Tree($this->tree()));

        (new Acl($server, ArrayPrivilegeResolver::asTheContractExpects()))->register();

        $options = new Options($server);

        $server->onMethod('OPTIONS', $options(...));

        self::assertSame('1, 3, access-control', $server->handle(new Request('OPTIONS', '/'))->headers()->first('DAV'));
    }

    /**
     * Without a request there is nobody asking, and nobody holds anything —
     * which is the same answer as for somebody who has not signed in.
     */
    public function testNobodyMayDoAnythingWhereThereIsNoRequestAtAll(): void
    {
        $result = new PropFindResult('calendars/work', PropFindForm::Named, ['{DAV:}current-user-privilege-set']);

        $this->plugin()->describe(new PropertiesRequested($result, new MemoryFile('work', '')));

        $answer = $result->byStatus()[200]['{DAV:}current-user-privilege-set'] ?? null;

        self::assertInstanceOf(Element::class, $answer);
        self::assertSame([], $answer->children());
    }

    /**
     * And once the request has been seen, whoever answers the one question
     * about identity decides what is reported.
     */
    public function testAsksWhoIsThereOnceTheRequestHasBeenSeen(): void
    {
        $server = new Server(new Tree($this->tree()));
        $plugin = new Acl($server, ArrayPrivilegeResolver::asTheContractExpects());

        $plugin->register();
        $server->events()->on(
            CurrentPrincipalRequested::class,
            static fn (CurrentPrincipalRequested $event) => $event->answerWith(self::ALICE),
        );

        $plugin->guardTheRequest(new BeforeMethod(new Request('PROPFIND', '/calendars/work')));

        $result = new PropFindResult('calendars/work', PropFindForm::Named, ['{DAV:}current-user-privilege-set']);

        $plugin->describe(new PropertiesRequested($result, new MemoryFile('work', '')));

        $answer = $result->byStatus()[200]['{DAV:}current-user-privilege-set'] ?? null;

        self::assertInstanceOf(Element::class, $answer);
        self::assertNotSame([], $answer->children());
    }

    private function tree(): MemoryCollection
    {
        $root = new MemoryCollection('');
        $calendars = new MemoryCollection('calendars');

        $calendars->add(new MemoryCollection('work'));
        $calendars->add(new MemoryCollection('private'));
        $root->add($calendars);

        return $root;
    }

    private function plugin(?ArrayPrivilegeResolver $resolver = null, ?Privilege $privileges = null): Acl
    {
        $server = new Server(new Tree($this->tree()));
        $plugin = new Acl($server, $resolver ?? ArrayPrivilegeResolver::asTheContractExpects(), $privileges);

        $plugin->register();

        return $plugin;
    }

    private function propFind(
        string $target,
        string $property,
        ?string $who = null,
        ?Privilege $privileges = null,
    ): string {
        $server = new Server(new Tree($this->tree()));

        (new Acl($server, ArrayPrivilegeResolver::asTheContractExpects(), $privileges))->register();

        // Somebody has to be signed in for any of this to be answered at
        // all: the plugin refuses a `PROPFIND` from whoever may not read
        // (R-ACL-05), which is what `AclEnforcementTest` is about.
        $server->events()->on(
            CurrentPrincipalRequested::class,
            static fn (CurrentPrincipalRequested $event) => $event->answerWith($who ?? self::ALICE),
        );

        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        return (string) $this->ask($server, $target, $property)->body();
    }

    private function ask(Server $server, string $target, string $property): Response
    {
        return $server->handle(new Request(
            'PROPFIND',
            $target,
            headers: new Headers(['Depth' => '0']),
            body: new Body(sprintf('<D:propfind xmlns:D="DAV:"><D:prop><D:%s/></D:prop></D:propfind>', $property)),
        ));
    }
}

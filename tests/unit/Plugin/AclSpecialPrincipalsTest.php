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

use DavServices\Acl\IPrivilegeResolver;
use DavServices\Acl\Privilege;
use DavServices\Acl\PrivilegeSet;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Http\Body;
use DavServices\Http\Headers;
use DavServices\Http\Request;
use DavServices\Plugin\Acl;
use DavServices\Tests\Unit\Dav\MemoryCollection;
use DavServices\Tests\Unit\Dav\MemoryFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test list, derived from RFC 3744 §5.5 and §5.5.1.
 *
 * **A `DAV:acl` that cannot say what the rules say is not incomplete, it is
 * untrue.** §5.5: the property "specifies the list of access control entries
 * (ACEs), which define what principals are to get what privileges for this
 * resource". A deployment whose rule is "everyone may read" and whose `DAV:acl`
 * names nobody has told a client something false about itself — and a client
 * reads that property precisely to find out what it may not try.
 *
 * §5.5.1 gives six forms a principal in an entry may take:
 *
 *     <!ELEMENT principal (href | all | authenticated | unauthenticated
 *      | property | self)>
 *
 * Until now this server wrote **only `DAV:href`**, because the resolver names
 * principals by URL. Four of the other five are empty elements, so a name is
 * all they need — and a URL never looks like `{DAV:}all`, so the same string
 * carries both without ambiguity.
 *
 * ## What stays out, and why that is said rather than hidden
 *
 * `DAV:property` carries a property name inside it ("if the DAV:property
 * element contained `<DAV:owner/>`, the current user would match … the
 * principal identified by the DAV:owner property of the resource"), and
 * `DAV:invert` wraps a whole principal. Neither fits in a name, so neither is
 * expressible through this seam. A deployment that grants by those forms
 * cannot report them — which is a limit worth writing down, since the
 * alternative is a server that quietly reports something else.
 */
#[CoversClass(Acl::class)]
final class AclSpecialPrincipalsTest extends TestCase
{
    /**
     * §5.5.1's four empty principals, each written as the element it is.
     *
     * @param string $principal As the resolver names it
     * @param string $written The element a client is to receive
     */
    #[DataProvider('theEmptyPrincipals')]
    public function testAnEmptyPrincipalIsWrittenAsItself(string $principal, string $written): void
    {
        $body = $this->acl([$principal => ['{DAV:}read']]);

        self::assertStringContainsString(sprintf('<d:principal>%s</d:principal>', $written), $body);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function theEmptyPrincipals(): iterable
    {
        // "The current user always matches DAV:all."
        yield 'everybody' => ['{DAV:}all', '<d:all/>'];

        // "…only if authenticated."
        yield 'whoever signed in' => ['{DAV:}authenticated', '<d:authenticated/>'];

        // "…only if not authenticated." The public, in other words.
        yield 'the public' => ['{DAV:}unauthenticated', '<d:unauthenticated/>'];

        // "…only if that resource is a principal and that principal matches
        // the current user."
        yield 'the principal itself' => ['{DAV:}self', '<d:self/>'];
    }

    /**
     * And a principal named by URL is still an href: the four are the
     * exception, not the rule.
     */
    public function testAPrincipalNamedByUrlIsStillAnHref(): void
    {
        $body = $this->acl(['/principals/alice' => ['{DAV:}read']]);

        self::assertStringContainsString(
            '<d:principal><d:href>/principals/alice</d:href></d:principal>',
            $body,
        );
    }

    /**
     * **The two kinds stand side by side in one list**, which is the point:
     * a deployment grants to a person and to everybody, and the property has
     * to be able to say both.
     */
    public function testBothKindsStandInTheSameList(): void
    {
        $body = $this->acl([
            '/principals/alice' => ['{DAV:}write'],
            '{DAV:}all' => ['{DAV:}read'],
        ]);

        self::assertStringContainsString('<d:href>/principals/alice</d:href>', $body);
        self::assertStringContainsString('<d:all/>', $body);
    }

    /**
     * **A name that is not one of the four is an href, whatever it looks
     * like.** Guessing would be inventing an entry form the specification
     * does not have there — `{DAV:}owner` is what a `DAV:property` principal
     * is written *around*, not a principal in itself.
     */
    public function testANameThatIsNotOneOfTheFourIsAnHref(): void
    {
        $body = $this->acl(['{DAV:}owner' => ['{DAV:}read']]);

        self::assertStringContainsString('<d:href>{DAV:}owner</d:href>', $body);
        self::assertStringNotContainsString('<d:owner/>', $body);
    }

    /**
     * The privileges beside them are unchanged: this is about who an entry
     * names, not what it grants.
     */
    public function testThePrivilegesAreWrittenAsBefore(): void
    {
        $body = $this->acl(['{DAV:}all' => ['{DAV:}read']]);

        self::assertStringContainsString('<d:grant><d:privilege><d:read/></d:privilege></d:grant>', $body);
    }

    /**
     * @param array<string, list<string>> $entries
     */
    private function acl(array $entries): string
    {
        $root = new MemoryCollection('');

        $root->add(new MemoryFile('work.ics', 'BEGIN:VCALENDAR'));

        $server = new Server(new Tree($root));
        $propFind = new PropFind($server);

        $server->onMethod('PROPFIND', $propFind(...));

        (new Acl($server, new class ($entries) implements IPrivilegeResolver {
            /**
             * @param array<string, list<string>> $entries
             */
            public function __construct(private readonly array $entries)
            {
            }

            /**
             * Everything, because this test is about what the property
             * **reports**, not about what the guard refuses — a reader who
             * may not read would never see the list at all.
             */
            public function forPath(?string $principalUri, string $path): PrivilegeSet
            {
                return PrivilegeSet::of(Privilege::standard(), '{DAV:}all');
            }

            public function forPaths(?string $principalUri, array $paths): array
            {
                $held = $this->forPath($principalUri, '');

                return array_map(static fn (): PrivilegeSet => $held, array_flip($paths));
            }

            public function principalsForPath(string $path): array
            {
                $tree = Privilege::standard();

                return array_map(
                    static fn (array $names): PrivilegeSet => PrivilegeSet::of($tree, ...$names),
                    $this->entries,
                );
            }
        }))->register(new Report($server));

        return (string) $server->handle(new Request(
            'PROPFIND',
            '/work.ics',
            headers: new Headers(['Depth' => '0']),
            body: new Body('<D:propfind xmlns:D="DAV:"><D:prop><D:acl/></D:prop></D:propfind>'),
        ))->body();
    }
}

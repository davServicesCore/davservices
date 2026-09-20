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

namespace DavServices\Plugin;

use DavServices\Dav\Event\BeforeMethod;
use DavServices\Dav\ICollection;
use DavServices\Dav\IFile;
use DavServices\Dav\INode;
use DavServices\Dav\Server;
use DavServices\Exception\IHttpFailure;
use DavServices\Http\HttpDate;
use DavServices\Http\Response;
use DavServices\Uri\MalformedPath;
use DavServices\Uri\Path;

/**
 * Shows what is in a collection, for somebody building something against the
 * server (R-DAV-10).
 *
 * A `GET` on a collection is a `405` in plain WebDAV: there is nothing to
 * send. This listens in front of the method and answers with a page instead,
 * so that a tree can be walked with an ordinary browser while an application
 * is being written.
 *
 * **It is not the web interface, and it is not to be switched on in
 * production.** A server that did would be publishing the shape of every
 * account to anyone who could reach the path, with no access control in front
 * of it — this library's own requirement (R-DAV-10) says so in as many words,
 * and so does the page it produces. That is why it is switched on by a line
 * that says what it is:
 *
 *     $browser = Browser::forDevelopment($server);
 *     $server->events()->on(BeforeMethod::class, $browser(...));
 *
 * Until those two lines are written, a server answers `405` as it did before.
 *
 * **What a client calls its files is a name, not markup.** Every piece of it
 * goes through one escape on its way into the page, because a calendar named
 * `<script>` would otherwise carry the client's own script back to the next
 * person who looked at the tree.
 */
final class Browser
{
    private function __construct(private readonly Server $server)
    {
    }

    /**
     * Makes one, and says at the call site what it is for.
     *
     * The name is the warning: R-DAV-10 asks that this never be switched on in
     * production, and a warning in a docblock is read by whoever opens the
     * file, while this one is read by whoever writes the line.
     */
    public static function forDevelopment(Server $server): self
    {
        return new self($server);
    }

    /**
     * Answers a `GET` on a collection with a listing, and leaves everything
     * else to the methods.
     */
    public function __invoke(BeforeMethod $event): void
    {
        $collection = $this->collectionOf($event);

        if ($collection === null) {
            return;
        }

        $event->answerWith(
            (new Response(200, body: $this->page($collection[0], $collection[1])))
                ->withHeader('Content-Type', 'text/html; charset=utf-8'),
        );
    }

    /**
     * The collection this request is a `GET` on, or null where it is anything
     * else at all.
     *
     * A `GET` on a file belongs to the method; so does one on a path that
     * names nothing, because a listing of nothing is a worse answer than the
     * `404` the method gives.
     *
     * @return array{string, ICollection}|null
     */
    private function collectionOf(BeforeMethod $event): ?array
    {
        $request = $event->request();

        if ($request->method() !== 'GET') {
            return null;
        }

        try {
            $path = $this->server->path($request);
            $node = $this->server->tree()->node($path);
        } catch (IHttpFailure | MalformedPath) {
            return null;
        }

        return $node instanceof ICollection ? [$path, $node] : null;
    }

    /**
     * The page itself.
     *
     * One template rather than a string built up in pieces: a document written
     * out as the document it is can be read as one, and there is nowhere for a
     * missing tag to hide between two concatenations.
     */
    private function page(string $path, ICollection $collection): string
    {
        $here = self::escaped(self::asACollection($this->server->href($path)));

        $template = <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="utf-8"><title>%s</title></head>
            <body>
            <h1>%s</h1>
            %s<ul>
            %s</ul>
            <p>This listing is a development aid of davServices. It is not a web interface, and it is not meant to be switched on where anybody else can reach it.</p>
            </body>
            </html>

            HTML;

        return sprintf($template, $here, $here, $this->wayBack($path), $this->members($path, $collection));
    }

    /**
     * The link to the collection above, and nothing at the root: it is a
     * member of nothing, and a link to itself would only go round.
     */
    private function wayBack(string $path): string
    {
        if ($path === '') {
            return '';
        }

        $up = self::asACollection($this->server->href(Path::split($path)[0]));

        return sprintf("<p><a href=\"%s\">Up</a></p>\n", self::escaped($up));
    }

    /**
     * One line per member: where it is, what it is called, and what the
     * backend can say about it without being asked twice.
     */
    private function members(string $path, ICollection $collection): string
    {
        $lines = '';

        foreach ($collection->children() as $member) {
            $href = $this->server->href(Path::join($path, $member->name()));

            $lines .= sprintf(
                "<li><a href=\"%s\">%s</a>%s</li>\n",
                self::escaped($member instanceof ICollection ? self::asACollection($href) : $href),
                self::escaped($member->name()),
                self::escaped(self::about($member)),
            );
        }

        return $lines;
    }

    /**
     * What is worth saying beside the name, and nothing a backend cannot say.
     */
    private static function about(INode $node): string
    {
        $said = [];
        $length = $node instanceof IFile ? $node->contentLength() : null;
        $changed = $node->lastModified();

        if ($length !== null) {
            $said[] = sprintf('%d bytes', $length);
        }

        if ($changed !== null) {
            $said[] = HttpDate::format($changed);
        }

        return $said === [] ? '' : ' — ' . implode(', ', $said);
    }

    /**
     * RFC 4918 §8.3: a collection is named with its trailing slash, and the
     * root already has the only one it needs.
     */
    private static function asACollection(string $href): string
    {
        return str_ends_with($href, '/') ? $href : $href . '/';
    }

    /**
     * The one door text goes through on its way into the page.
     *
     * `ENT_QUOTES` because a name stands in an attribute as well as between
     * tags, and `ENT_SUBSTITUTE` because a name that is not valid UTF-8 would
     * otherwise come back as an empty string — a file that vanished from the
     * listing rather than one that was shown safely.
     */
    private static function escaped(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

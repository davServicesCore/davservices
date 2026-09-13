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

namespace DavServices\Dav\Method;

use DateTimeInterface;
use DavServices\Dav\IFile;
use DavServices\Dav\Server;
use DavServices\Exception\MethodNotAllowed;
use DavServices\Exception\RangeNotSatisfiable;
use DavServices\Http\ByteRange;
use DavServices\Http\Request;
use DavServices\Http\Response;

/**
 * Answers `GET` and `HEAD`: the content of a file, or part of it.
 *
 * Two things this method is careful not to do.
 *
 * **It never reads a file to send it.** What the node hands over is what goes
 * out, stream and all (R-TREE-02), and a range is served from that same stream
 * by telling the answer where its part ends. A server that read files into
 * strings would fall over on the first large one rather than the tenth.
 *
 * **`HEAD` answers exactly as `GET` does, minus the body** (R-HTTP-10).
 * Clients use it to check a length or an entity tag before committing to a
 * transfer, and one that got different headers from the two would either
 * transfer needlessly or refuse to transfer at all. The same code answers
 * both, and the body is dropped at the end.
 *
 * Registered like any other method:
 *
 *     $get = new Get($server);
 *     $server->onMethod('GET', $get(...));
 *     $server->onMethod('HEAD', $get(...));
 */
final class Get
{
    public function __construct(private readonly Server $server)
    {
    }

    /**
     * Answers the request, whole or in part, with or without a body.
     *
     * @throws MethodNotAllowed If the path names a collection rather than a file
     */
    public function __invoke(Request $request): Response
    {
        $node = $this->server->tree()->node($this->server->path($request));

        if (!$node instanceof IFile) {
            // It is there, so this is no `404`; `GET` simply does not apply to
            // it while nothing is registered to make a page out of it.
            throw new MethodNotAllowed('A collection cannot be fetched.');
        }

        $response = $this->describe($node, new Response(200))
            ->withHeader('Accept-Ranges', 'bytes');

        $response = $this->send($node, $request, $response);

        return $request->method() === 'HEAD' ? $response->withBody(null) : $response;
    }

    /**
     * What the node can say about itself, and nothing it cannot.
     *
     * A backend that does not know the length, the type or the entity tag of
     * what it holds is the ordinary case rather than the odd one. What it
     * cannot say is left out rather than invented: a wrong entity tag is worse
     * than none at all, because a client will believe it.
     */
    private function describe(IFile $file, Response $response): Response
    {
        $response = self::withField($response, 'Content-Type', $file->contentType());
        $response = self::withField($response, 'ETag', $file->etag());

        return self::withField($response, 'Last-Modified', self::httpDate($file->lastModified()));
    }

    /**
     * The field, where there is anything to say.
     */
    private static function withField(Response $response, string $field, ?string $value): Response
    {
        return $value === null ? $response : $response->withHeader($field, $value);
    }

    /**
     * The content, whole or in part.
     *
     * @throws RangeNotSatisfiable If the range asks for bytes the file has not
     */
    private function send(IFile $file, Request $request, Response $response): Response
    {
        $size = $file->contentLength();

        // Without a length there is nothing to measure a range against, and
        // nothing to promise either: the whole file goes out, and the client
        // is told neither how long it is nor that part of it was possible.
        if ($size === null) {
            return $response->withBody($file->get());
        }

        $header = $request->headers()->first('Range');

        if (!self::isSatisfiable($header, $size)) {
            // RFC 9110 §15.5.17: say how long the file really is, so that the
            // client can ask again for something that is there. Only this
            // method knows the size, which is why the refusal becomes an
            // answer here rather than at the server.
            return $response
                ->withStatus(416)
                ->withHeader('Content-Range', sprintf('bytes */%d', $size));
        }

        $range = ByteRange::parse($header, $size);

        if ($range === null) {
            return $response
                ->withBody($file->get())
                ->withHeader('Content-Length', (string) $size);
        }

        return $response
            ->withStatus(206)
            ->withBody(self::from($file, $range->start(), $range->length()), $range->length())
            ->withHeader('Content-Length', (string) $range->length())
            ->withHeader('Content-Range', $range->contentRange());
    }

    /**
     * Is there anything to send for this range?
     *
     * The refusal of an unsatisfiable range is a status this method has to
     * dress with the length of the file, so it is asked as a question here
     * rather than caught as a failure in the middle of building an answer.
     */
    private static function isSatisfiable(?string $header, int $size): bool
    {
        try {
            ByteRange::parse($header, $size);
        } catch (RangeNotSatisfiable) {
            return false;
        }

        return true;
    }

    /**
     * The part of the file a range names.
     *
     * A stream is wound forward and left as it is — where it ends is on the
     * answer, so that serving part of a recording costs no more than serving
     * part of a note. A string is cut to size here, because there is nothing
     * to be saved by carrying the rest of it around.
     *
     * @return resource|string
     */
    private static function from(IFile $file, int $start, int $length): mixed
    {
        $content = $file->get();

        if (is_string($content)) {
            return substr($content, $start, $length);
        }

        fseek($content, $start);

        return $content;
    }

    /**
     * A date in the one format of RFC 9110 §5.6.7, in GMT whatever the
     * server's own time zone is: a client compares it byte for byte with what
     * it stored.
     */
    private static function httpDate(?DateTimeInterface $moment): ?string
    {
        if ($moment === null) {
            return null;
        }

        return gmdate('D, d M Y H:i:s \G\M\T', $moment->getTimestamp());
    }
}

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

use DavServices\Dav\Event\AfterBind;
use DavServices\Dav\Event\BeforeBind;
use DavServices\Dav\Server;
use DavServices\Event\IEvent;
use DavServices\Exception\BadGateway;
use DavServices\Exception\BadRequest;
use DavServices\Exception\Forbidden;
use DavServices\Exception\IHttpFailure;
use DavServices\Exception\NotFound;
use DavServices\Exception\PreconditionFailed;
use DavServices\Http\Request;
use DavServices\Http\Response;
use DavServices\Xml\MultiStatus;

/**
 * What `COPY` and `MOVE` have in common, which is nearly all of it.
 *
 * Both are about **two** paths, and most of what can go wrong is about the
 * second one: it may be on another server, may already hold something the
 * client does not want replaced, may lie inside what is being carried over.
 * Each of those is a different answer, and getting them right once is worth
 * more than getting them right twice.
 *
 * Three of the answers are worth reading closely.
 *
 * **A destination this server does not serve is a `502`**, whether it names
 * another host or a path outside what this server is mounted at (RFC 4918
 * §9.8.5). It is not a `403`: nothing about it is a matter of permission, and
 * a client told so would go looking for a way to ask nicely.
 *
 * **A destination inside the source is a `403`.** A client that asks to copy
 * `/calendars` into `/calendars/backup` is asking for a copy that contains
 * itself, and a server that starts on one does not stop until something runs
 * out.
 *
 * **What is already there goes first** (§9.8.4). `Overwrite: T` is the
 * default, and the destination is removed as though by a `DELETE` before
 * anything is written. That is the specification's own order, and it is worth
 * knowing that a copy which fails after it has left the client with neither
 * the old resource nor a new one.
 */
abstract class Transfer
{
    public function __construct(protected readonly Server $server)
    {
    }

    /**
     * Carries the resource over, and says what became of it.
     *
     * @throws NotFound If there is nothing at the source path
     * @throws BadRequest If the `Destination`, `Overwrite` or `Depth` is not
     *                    one this protocol has
     * @throws BadGateway If the destination is not served here
     * @throws Forbidden If the destination lies inside the source
     * @throws PreconditionFailed If something is there and may not be replaced
     * @throws IHttpFailure Whatever the source itself was refused with
     */
    public function __invoke(Request $request): Response
    {
        $from = $this->server->path($request);

        // Asked for before anything else, so that a request naming nothing is
        // answered with a `404` rather than with a complaint about a header.
        $this->server->tree()->node($from);

        $to = $this->destination($request);

        $this->refuseAnImpossibleDestination($from, $to);

        $this->refuseADepthThisMethodDoesNotTake($request);

        // Read before it is needed, because a header this server cannot make
        // sense of is a client error whether or not anything is in the way.
        // Answering `201` to a request that was not understood is the one
        // thing worse than refusing it.
        $mayOverwrite = self::mayOverwrite($request);
        $wasThereAlready = $this->server->tree()->exists($to);

        if ($wasThereAlready && !$mayOverwrite) {
            throw new PreconditionFailed('Something is there already, and this request said not to replace it.');
        }

        $this->server->events()->emit($this->before($from, $to));
        $this->server->events()->emit(new BeforeBind($to));

        if ($wasThereAlready) {
            $this->makeRoom($to);
        }

        $failures = $this->carryOut($request, $from, $to);

        if ($failures === []) {
            $this->server->events()->emit($this->after($from, $to));
            $this->server->events()->emit(new AfterBind($to));
        }

        return $this->answer($from, $wasThereAlready, $failures);
    }

    /**
     * Refuses a `Depth` this method does not have.
     *
     * Asked before anything is removed: what is at the destination goes first,
     * and a request that was never going to work would have taken it with it.
     *
     * @throws BadRequest If the `Depth` is not one this method has
     */
    abstract protected function refuseADepthThisMethodDoesNotTake(Request $request): void;

    /**
     * Raised before anything is carried over; a listener refuses by throwing.
     */
    abstract protected function before(string $from, string $to): IEvent;

    /**
     * Raised once it has been carried over whole.
     */
    abstract protected function after(string $from, string $to): IEvent;

    /**
     * Does the carrying.
     *
     * @return array<string, IHttpFailure> What would not go, by source path
     */
    abstract protected function carryOut(Request $request, string $from, string $to): array;

    /**
     * The `Depth` of the request, in the one spelling the rest may compare
     * against.
     *
     * RFC 5234 §2.3: what a grammar spells out in letters is matched without
     * regard to case. A missing header is `infinity` for both methods (RFC
     * 4918 §9.8.3 and §9.9.2).
     */
    protected static function depthOf(Request $request): string
    {
        return strtolower($request->headers()->first('Depth') ?? 'infinity');
    }

    /**
     * Where the request says the resource is to end up.
     *
     * @throws BadRequest If there is no `Destination`, or it cannot be read
     * @throws BadGateway If it names something this server does not serve
     */
    private function destination(Request $request): string
    {
        $destination = $request->headers()->first('Destination');

        if ($destination === null) {
            throw new BadRequest(sprintf('A %s needs a Destination.', $request->method()));
        }

        try {
            return $this->server->pathOfUrl($destination, $request);
        } catch (NotFound $elsewhere) {
            // Another host, or this one but not this server's tree: either
            // way somebody else lives there and this cannot write into it.
            throw new BadGateway('That destination is not served here.', null, $elsewhere);
        }
    }

    /**
     * RFC 4918 §9.8.4: `T` is the default, and `F` is a client saying it does
     * not know what is there and would rather be told.
     *
     * @throws BadRequest If the header says something else
     */
    private static function mayOverwrite(Request $request): bool
    {
        $overwrite = strtoupper($request->headers()->first('Overwrite') ?? 'T');

        if ($overwrite !== 'T' && $overwrite !== 'F') {
            throw new BadRequest('An Overwrite is T or F.');
        }

        return $overwrite === 'T';
    }

    /**
     * @throws Forbidden If the destination is the root, or lies inside the
     *                   source
     */
    private function refuseAnImpossibleDestination(string $from, string $to): void
    {
        // The root is a member of no collection, so nothing can be put in its
        // place. Refused here rather than later, because what is at the
        // destination is removed before the copy is made — and a request that
        // was never going to work would have emptied the server first.
        if ($to === '') {
            throw new Forbidden('The root of this server is a member of nothing, and cannot be replaced.');
        }

        // Everything lies below the root, so a copy of it can only ever be a
        // copy of something into itself.
        if ($from === '' || $to === $from || str_starts_with($to, $from . '/')) {
            throw new Forbidden('A resource cannot be carried into itself.');
        }
    }

    /**
     * RFC 4918 §9.8.4: what is at the destination is removed first, as a
     * `DELETE` of infinite depth would remove it.
     *
     * @throws IHttpFailure If it will not go
     */
    private function makeRoom(string $to): void
    {
        // What the tree kept about it is forgotten by the copy that follows:
        // it forgets the collection the destination lies in, and everything
        // below that with it.
        $this->server->tree()->node($to)->delete();
    }

    /**
     * @param array<string, IHttpFailure> $failures
     *
     * @throws IHttpFailure Where the source itself was refused
     */
    private function answer(string $from, bool $wasThereAlready, array $failures): Response
    {
        if ($failures === []) {
            return new Response($wasThereAlready ? 204 : 201);
        }

        // A refusal of the resource the client named is a plain status: a
        // `207` is there to say something about a second resource.
        $refusalOfTheSource = $failures[$from] ?? null;

        if ($refusalOfTheSource !== null) {
            throw $refusalOfTheSource;
        }

        $report = new MultiStatus();

        foreach ($failures as $path => $refusal) {
            $report->addFailure($this->server->href($path), $refusal);
        }

        return (new Response(207, body: $this->server->writer()->write($report->toElement())))
            ->withHeader('Content-Type', 'application/xml; charset=utf-8');
    }
}

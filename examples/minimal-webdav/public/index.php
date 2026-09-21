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

use DavServices\Backend\File\Directory;
use DavServices\Backend\File\LockBackend;
use DavServices\Backend\File\PropertyStorage;
use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\Method\Copy;
use DavServices\Dav\Method\Delete;
use DavServices\Dav\Method\Get;
use DavServices\Dav\Method\MkCol;
use DavServices\Dav\Method\Move;
use DavServices\Dav\Method\Options;
use DavServices\Dav\Method\PropFind;
use DavServices\Dav\Method\PropPatch;
use DavServices\Dav\Method\Report;
use DavServices\Dav\Method\Put;
use DavServices\Dav\Property\LiveProperties;
use DavServices\Dav\Server;
use DavServices\Dav\Tree;
use DavServices\Event\EventEmitter;
use DavServices\Http\Sapi;
use DavServices\Plugin\DeadProperties;
use DavServices\Plugin\Locks;

/*
 * A WebDAV server in one file.
 *
 * Everything an application has to do is here and in this order: say where the
 * data goes, register the methods it means to answer, register the plugins it
 * wants, and hand the request over.
 *
 * It has no authentication and no access control. Anyone who can reach it can
 * read and write everything — which is what makes it short enough to read, and
 * what makes it a thing to run on your own machine rather than on a network.
 */

// The library needs no Composer: this is its own autoloader.
require dirname(__DIR__, 3) . '/autoload.php';

$data = dirname(__DIR__) . '/var';
$files = $data . '/files';
$properties = $data . '/properties';
$locks = $data . '/locks';

/*
 * The backends want directories that are already there, on purpose: making one
 * up is how a typo ends with a store in a web root. An application knows where
 * its data belongs, so it is the application that makes them.
 */
foreach ([$files, $properties, $locks] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0o755, true)) {
        http_response_code(500);

        exit(sprintf('"%s" could not be made.', $directory));
    }
}

/*
 * What a plugin takes part through. The live properties — resourcetype, the
 * length, the entity tag — are listeners like any other, and the properties a
 * filesystem has nowhere to put are kept beside it.
 */
$events = new EventEmitter();
$live = new LiveProperties();

$events->on(PropertiesRequested::class, $live(...));
(new DeadProperties(new PropertyStorage($properties)))->registerOn($events);

$server = new Server(new Tree(new Directory($files)), $events);

/*
 * A server answers the methods it is given and `501` to everything else. GET
 * and HEAD are one method class, because the two answer alike but for the body.
 */
$get = new Get($server);

$server->onMethod('GET', $get(...));
$server->onMethod('HEAD', $get(...));

$methods = [
    'OPTIONS' => new Options($server),
    'PUT' => new Put($server),
    'DELETE' => new Delete($server),
    'MKCOL' => new MkCol($server),
    'PROPFIND' => new PropFind($server),
    'PROPPATCH' => new PropPatch($server),
    'COPY' => new Copy($server),
    'MOVE' => new Move($server),
];

foreach ($methods as $name => $method) {
    $server->onMethod($name, $method(...));
}

/*
 * `REPORT` answers whatever its body asks for, by name. The reports this
 * library brings are about principals, and this server has none — so it
 * registers no report, and that is the honest answer rather than a gap.
 *
 * The method is switched on all the same, so that `DAV:supported-report-set`
 * is answered by the thing that knows the reports rather than by the one
 * that knows none: a client is told there are none, which is true.
 */
(new Report($server))->register();

/*
 * Locking is a plugin, and a server built without these two lines is a plain
 * WebDAV server rather than a broken one: it answers `DAV: 1` and `501` to a
 * LOCK. With them it is of compliance class 2 and brings its own two methods.
 *
 * An hour is the longest a lock is handed out for here, whatever a client
 * asks: a lock that outlives the client that took it is one somebody has to
 * clear by hand.
 */
(new Locks($server, new LockBackend($locks), 3600))->register();

/*
 * The seam to PHP itself: `$_SERVER` in, one answer out.
 *
 * The directory browser is deliberately not registered. It is a development
 * aid and publishes the shape of everything behind it; see the README.
 */
$sapi = new Sapi();

$sapi->send($server->handle($sapi->request($_SERVER)));

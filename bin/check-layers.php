<?php

/*
 * This file is part of davServices.
 *
 * (c) Felix Böck <https://dav.services>
 *
 * Licensed under the Apache License, Version 2.0.
 * For the full copyright and license information, see the LICENSE file.
 */

/**
 * Fails on a dependency that points from a lower layer to a higher one.
 *
 * Layer order (lowest first): VObject, Event, Uri, Http, Xml, Dav, Backend,
 * Plugin. Http may not know about Dav; Dav may not know about Plugin; and so
 * on.
 *
 * VObject lies at the very bottom because it is a reader and writer for two
 * data formats and knows nothing else: not the transport, not the tree, not
 * a request. Putting it there is what says so — it may use nothing above it,
 * and everything above may use it, which CalDav and CardDav will.
 *
 * Backend lies below Plugin because that is the direction the dependency runs
 * in: a plugin is written against a backend's interface — the lock plugin
 * against a lock backend, the dead properties against a property storage —
 * and no backend has any business knowing which plugin is using it.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/Scanner.php';

/** Lower index means lower layer. */
const LAYERS = ['VObject', 'Event', 'Uri', 'Http', 'Xml', 'Dav', 'Backend', 'Acl', 'CalDav', 'CardDav', 'Plugin'];

$rank = array_flip(LAYERS);
$violations = [];

foreach (Scanner::productionFiles() as $file) {
    $namespace = Scanner::declaredNamespace($file);
    $ownLayer = Scanner::layerOf($namespace, LAYERS);

    if ($ownLayer === null) {
        continue;
    }

    foreach (Scanner::useStatements($file) as $line => $fqcn) {
        $otherLayer = Scanner::layerOf($fqcn, LAYERS);

        if ($otherLayer === null || $otherLayer === $ownLayer) {
            continue;
        }

        // layerOf() only ever returns a member of LAYERS, but the ranks are
        // looked up rather than asserted so that a future layer added in one
        // place and forgotten in the other cannot pass unnoticed.
        $ownRank = $rank[$ownLayer] ?? null;
        $otherRank = $rank[$otherLayer] ?? null;

        if ($ownRank === null || $otherRank === null) {
            $violations[] = sprintf('%s  unknown layer in %s', Scanner::rel($file), $fqcn);
            continue;
        }

        if ($otherRank > $ownRank) {
            $violations[] = sprintf(
                '%s:%d  %s must not depend on %s (%s)',
                Scanner::rel($file),
                $line,
                $ownLayer,
                $otherLayer,
                $fqcn,
            );
        }
    }
}

Scanner::report('layer direction', $violations);

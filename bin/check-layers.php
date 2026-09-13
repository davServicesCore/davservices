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
 * Layer order (lowest first): Event, Uri, Http, Xml, Dav, Plugin, Backend.
 * Http may not know about Dav; Dav may not know about Plugin; and so on.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/Scanner.php';

/** Lower index means lower layer. */
const LAYERS = ['Event', 'Uri', 'Http', 'Xml', 'Dav', 'Acl', 'CalDav', 'CardDav', 'Plugin', 'Backend'];

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

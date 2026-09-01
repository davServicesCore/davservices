<?php

/**
 * Fails on a dependency that points from a lower layer to a higher one.
 *
 * Layer order (lowest first): Http, Xml, Dav, Plugin, Backend.
 * Http may not know about Dav; Dav may not know about Plugin; and so on.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/Scanner.php';

/** Lower index means lower layer. */
const LAYERS = ['Uri', 'Http', 'Xml', 'Dav', 'Acl', 'CalDav', 'CardDav', 'Plugin', 'Backend'];

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

        if ($rank[$otherLayer] > $rank[$ownLayer]) {
            $violations[] = sprintf(
                '%s:%d  %s must not depend on %s (%s)',
                Scanner::rel($file),
                $line,
                $ownLayer,
                $otherLayer,
                $fqcn
            );
        }
    }
}

Scanner::report('layer direction', $violations);

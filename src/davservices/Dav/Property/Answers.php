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

namespace DavServices\Dav\Property;

use DavServices\Dav\Event\PropertiesRequested;
use DavServices\Dav\INode;
use DavServices\Dav\IProperties;
use DavServices\Dav\PropFindForm;
use DavServices\Dav\PropFindResult;
use DavServices\Event\EventEmitter;

/**
 * What this server says about the properties of one resource.
 *
 * **There is one way to find that out, and this is it.** It was private
 * inside `PROPFIND` until `DAV:principal-property-search` needed the same
 * answer — a search compares property values, and values it assembled for
 * itself would be values no client ever sees. Two assemblies would be two
 * places that have to agree about who answers first, and they would drift.
 *
 * Two rules live here and both carry weight.
 *
 * **The listeners answer first, the node after them** (R-PROP-05), and the
 * first answer for a property stands. That is how access control refuses a
 * property the node would hand over quite happily: it takes the first word,
 * so that nothing behind it can supply the value the client was not to see.
 * An assembly that asked the node first would defeat that silently.
 *
 * **The node is asked only what is still open.** A backend asked for what a
 * plugin has already answered runs a query for nothing, and over a listing of
 * two hundred members that is two hundred of them.
 */
final class Answers
{
    /**
     * Everyone who has something to say about this resource, in order.
     *
     * The result comes back whole rather than as `propstat` blocks, because
     * the callers want different things from it: a `PROPFIND` writes the
     * blocks, and a search reads the values that may be read.
     *
     * @param string $path The path inside the tree, which listeners need in
     *                     order to decide — access control asks about the
     *                     path, not about the node
     * @param list<string> $names What was named, where the form names
     *                            anything
     */
    public static function about(
        EventEmitter $events,
        string $path,
        INode $node,
        PropFindForm $form,
        array $names = [],
    ): PropFindResult {
        $result = new PropFindResult($path, $form, $names);

        // The listeners first: a plugin that must refuse a property the node
        // would hand over can only do it by answering before the node does.
        $events->emit(new PropertiesRequested($result, $node));

        if ($node instanceof IProperties) {
            self::askTheNode($result, $node);
        }

        return $result;
    }

    /**
     * What the node itself keeps, and only what is still open.
     */
    private static function askTheNode(PropFindResult $result, IProperties $node): void
    {
        if ($result->form() === PropFindForm::NamesOnly) {
            // The values are dropped from the answer, so fetching them would
            // turn the cheap question into the expensive one.
            foreach ($node->propertyNames() as $name) {
                $result->set($name, null);
            }

            return;
        }

        foreach ($node->properties(self::namesToAskFor($result, $node)) as $name => $value) {
            $result->set($name, $value);
        }
    }

    /**
     * Under `allprop` the node's own names are the question: a property
     * nobody has named can be learnt of in no other way, and every dead
     * property is one of those.
     *
     * @return list<string>
     */
    private static function namesToAskFor(PropFindResult $result, IProperties $node): array
    {
        $candidates = $result->form() === PropFindForm::Everything
            ? $node->propertyNames()
            : $result->stillWanted();

        $names = [];

        foreach ($candidates as $name) {
            if ($result->wants($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}

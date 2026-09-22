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
 * Fails if application-layer vocabulary appears in the protocol library.
 *
 * davServices knows about principals and privileges. It must never learn about application-specific concepts such as spaces, roles or memberships. An architecture rule that only lives in a document erodes under deadline pressure; this check breaks the build instead.
 *
 * Identifiers are split into words before matching, so `spaceAdminRole` and `space_admin_role` are caught while `namespace` is not.
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

require __DIR__ . '/lib/Scanner.php';

/** Lower-case words that must not appear as an identifier component in shipped code. */
const FORBIDDEN = [
    'space', 'subspace', 'tenant', 'mandant', 'bereich',
    'role', 'rolle', 'superadmin', 'spaceadmin', 'membership',
    'apppassword', 'session', 'auditlog', 'publication',
];

/**
 * A forbidden word that a specification itself put after another one, where
 * the name is not this library's to change.
 *
 * `DAV:group-membership` is RFC 3744 §4.4, and support for it is REQUIRED. The
 * rule it would otherwise trip is a real one — this library must never learn
 * about memberships of an application's own invention — but a property name
 * out of the specification is not that, and renaming it would mean answering
 * a property no client ever asks for.
 *
 * Keyed by the forbidden word, valued by the word that has to come before it.
 * Anything else keeping the same company is still caught: `spaceMembership`
 * trips, `group_membership` does not.
 *
 * @var array<string, string>
 */
const FROM_A_SPECIFICATION = [
    'membership' => 'group',
];

/**
 * Splits an identifier into lower-case words.
 *
 * getSpaceAdmin  → get, space, admin
 * space_admin_id → space, admin, id
 * namespace      → namespace   (single word, deliberately not "name" + "space")
 *
 * @return list<string>
 */
function words(string $identifier): array
{
    $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $identifier);
    $parts = preg_split('/[\s_\-]+/', (string) $spaced, -1, PREG_SPLIT_NO_EMPTY);

    if ($parts === false) {
        return [];
    }

    return array_map('strtolower', $parts);
}

$violations = [];

foreach (Scanner::productionFiles() as $file) {
    $tokens = token_get_all((string) file_get_contents($file));

    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }

        // Comments may mention these words when explaining what the library deliberately does not do.
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
            continue;
        }

        if (!in_array($token[0], [T_STRING, T_VARIABLE, T_CONSTANT_ENCAPSED_STRING], true)) {
            continue;
        }

        $identifier = ltrim(trim($token[1], "'\""), '$');
        $parts = words($identifier);
        $found = array_intersect($parts, FORBIDDEN);

        foreach ($found as $where => $term) {
            // A word the specification itself put after another one. The
            // position matters: it is `group-membership` that is allowed, not
            // the word on its own.
            $before = $parts[$where - 1] ?? '';

            if (isset(FROM_A_SPECIFICATION[$term]) && str_ends_with($before, FROM_A_SPECIFICATION[$term])) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d  "%s" contains application-layer term "%s"',
                Scanner::rel($file),
                $token[2],
                $identifier,
                $term,
            );
        }
    }
}

Scanner::report('forbidden application-layer terms', array_values(array_unique($violations)));

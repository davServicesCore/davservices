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

namespace DavServices\Backend;

/**
 * Where a user-id and a password become a person, or nothing.
 *
 * **This library keeps no passwords and compares none.** Who exists is the
 * application's business — {@see IPrincipalBackend} says so already — and
 * whoever knows the people knows how their passwords are kept: a hash in a
 * table, a bind against a directory, a token exchanged with an identity
 * provider. A library that held them would be holding the one thing it has
 * no way to hold well.
 *
 * So the whole of the seam is one question, and the answer is a name or
 * nothing.
 *
 * ## The name, not the path
 *
 * What comes back is **the member name inside the principal collection**, not
 * a URL and not a path. A backend knows no more about where principals hang
 * than {@see IPrincipalBackend} does, and the plugin that asked knows where
 * it mounted them.
 *
 * The user-id and the name need not be the same. Somebody may sign in as
 * `a.mueller` and be the principal `mueller`; that mapping is exactly the
 * kind of thing a deployment has and a protocol library cannot guess.
 *
 * ## What an implementation owes
 *
 * **The same work whether the user exists or not.** A backend that returns
 * early for an unknown user-id, and hashes a password for a known one, has
 * told an attacker which user-ids exist — in the one currency that no
 * firewall filters, time. Compare with `hash_equals()`, and do the work
 * either way.
 *
 * **The octets as they arrived.** RFC 7617 §2 leaves the character encoding
 * of the credentials deliberately undefined, "as long as it is compatible
 * with US-ASCII", so nothing above has converted them and nothing here
 * should either. Where a server announces `charset="UTF-8"` the client is
 * asked to send NFC-normalised UTF-8 (§2.1) — a deployment whose stored
 * passwords are normalised differently will not match, and that is a
 * deployment's business rather than a protocol's.
 */
interface IAuthBackend
{
    /**
     * The principal this user-id and password belong to, or null.
     *
     * Null is the only refusal: a wrong password, an unknown user-id and a
     * locked account are all "nobody", because telling them apart is telling
     * an attacker which user-ids exist.
     *
     * @param string $userId What the client sent before the first colon
     * @param string $password Everything after it, colons and all
     *
     * @return string|null The member name inside the principal collection
     */
    public function principalFor(string $userId, string $password): ?string;
}

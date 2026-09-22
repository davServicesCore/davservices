-- The table Backend\Pdo\PrincipalBackend reads the people from.
--
-- This library creates nothing: it has no migration tool and no business
-- deciding when your schema changes. Run this once, by hand or through
-- whatever you already use for the rest of your tables.
--
-- It is written to run unchanged on SQLite, MySQL and PostgreSQL. The lengths
-- are what an index takes everywhere: MySQL's InnoDB will not index more than
-- 768 characters of utf8mb4.
--
-- `alternate_uris` holds one URI per line rather than a table of its own.
-- That is a reference backend being a reference backend: a deployment with
-- its people in a directory or an identity provider writes its own backend
-- against that, and one that really keeps them here can normalise the column
-- away without this library noticing — nothing is written through it.

-- `group_membership` holds the groups a principal is **directly** in, one
-- name per line, and `group_members` the principals **directly** in it. Both
-- are direct because RFC 3744 §4.3 and §4.4 say so in as many words; the
-- recursion of §2 happens where access is worked out, not in the storage.
--
-- `group_members` is NULL where this server does not say who is in a group --
-- §4.3 is the one property of §4 a server need not support -- and empty where
-- the group has nobody in it yet. Those are different answers, so the column
-- is nullable on purpose.

CREATE TABLE davservices_principals (
    name             VARCHAR(255) NOT NULL PRIMARY KEY,
    display_name     VARCHAR(255) NULL,
    alternate_uris   TEXT         NULL,
    group_membership TEXT         NULL,
    group_members    TEXT         NULL
);

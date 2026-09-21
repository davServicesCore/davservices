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

CREATE TABLE davservices_principals (
    name           VARCHAR(255) NOT NULL PRIMARY KEY,
    display_name   VARCHAR(255) NULL,
    alternate_uris TEXT         NULL
);

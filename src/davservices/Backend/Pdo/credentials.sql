-- The table Backend\Pdo\AuthBackend reads the credentials from.
--
-- This library creates nothing: it has no migration tool and no business
-- deciding when your schema changes. Run this once, by hand or through
-- whatever you already use for the rest of your tables.
--
-- It is written to run unchanged on SQLite, MySQL and PostgreSQL. The lengths
-- are what an index takes everywhere: MySQL's InnoDB will not index more than
-- 768 characters of utf8mb4.
--
-- `password_hash` holds whatever PHP's `password_hash()` wrote, so the
-- algorithm and its cost belong to the deployment rather than to this
-- library. 255 characters is what the documentation asks for: bcrypt needs
-- 60 today, and the column is meant to outlive that.
--
-- `principal` is the member name inside the principal collection, and it need
-- not be the user-id. Somebody signs in as `c.carter` and is the principal
-- `carol`; that mapping is exactly the kind of thing a deployment has and a
-- protocol library cannot guess.
--
-- This is a table of its own rather than columns on the principals. The two
-- seams are apart on purpose: a principal row is read to answer PROPFIND, and
-- a hash is never something a client is told. Apart, a later mistake about
-- who may read the principals is not also a mistake about credentials -- and
-- the two can live in different databases, or this one can be left out
-- entirely by a deployment that writes its own backend against a directory.

-- One caveat that differs between databases, and it decides who gets in.
-- RFC 7617 section 2.1 has recipients support the PRECIS
-- "UsernameCasePreserved" profile, whose comparison is case-sensitive.
-- SQLite and PostgreSQL compare this column that way by default; MySQL's
-- usual collations (utf8mb4_general_ci, utf8mb4_0900_ai_ci) do not, so there
-- `Alice` signs in as `alice`. A deployment that wants the specified
-- behaviour declares the column with a binary or _bin collation. This file
-- says it rather than choosing, because a collation clause here would stop
-- the statement running unchanged on all three.

CREATE TABLE davservices_credentials (
    user_id       VARCHAR(255) NOT NULL PRIMARY KEY,
    password_hash VARCHAR(255) NOT NULL,
    principal     VARCHAR(255) NOT NULL
);

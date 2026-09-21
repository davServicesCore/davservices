-- The table Backend\Pdo\LockBackend keeps write locks in.
--
-- This library creates nothing: it has no migration tool and no business
-- deciding when your schema changes. Run this once, by hand or through
-- whatever you already use for the rest of your tables.
--
-- It is written to run unchanged on SQLite, MySQL and PostgreSQL. The lengths
-- are what an index takes everywhere: MySQL's InnoDB will not index more than
-- 768 characters of utf8mb4, and a path longer than 500 is not a path anybody
-- typed.

CREATE TABLE davservices_locks (
    token      VARCHAR(255)  NOT NULL PRIMARY KEY,
    root       VARCHAR(500)  NOT NULL,
    scope      VARCHAR(9)    NOT NULL,
    deep       SMALLINT      NOT NULL,
    expires_at BIGINT        NULL,
    owner      TEXT          NULL
);

-- Every read asks about a path, and every write asks about a token. The token
-- is the primary key; this is the other half.
CREATE INDEX davservices_locks_root ON davservices_locks (root);

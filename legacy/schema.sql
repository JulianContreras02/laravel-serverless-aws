-- Legacy example: NOT used by the Laravel application. See docs/migration-guide.md.
-- Applied by hand on the server; there is no migration history.

CREATE TABLE links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(10) NOT NULL,           -- [L11] case-insensitive collation, and no UNIQUE index [L12]
    url TEXT NOT NULL,
    hits INT NOT NULL DEFAULT 0,         -- [L4] counter rewritten on every click
    created DATETIME NOT NULL
);

CREATE TABLE clicks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    link_id INT NOT NULL,                -- no foreign key
    clicked DATETIME NOT NULL,           -- written with the server's local clock (NOW())
    referer TEXT,                        -- full URL, may contain personal data
    ip VARCHAR(45)                       -- kept forever
);                                       -- [L5] no index on (link_id, clicked)

# Legacy version (reference only)

A deliberately typical plain-PHP link shortener, the kind of code a client hands over: Apache with
`.htaccess`, `mysqli` with concatenated SQL, sessions and logs on the server's disk, and a crontab.
It is not wired to anything in this repository, is never deployed, and is excluded from Pint and
Larastan.

Each `[L1]` … `[L12]` marker in the code points to a row of the
[migration guide](../docs/migration-guide.md), which shows what replaced it and why.

| File | Role |
|---|---|
| [`public/index.php`](public/index.php) | Redirects, admin login, link creation and stats in one script |
| [`public/.htaccess`](public/.htaccess) | URL rewriting in the web server |
| [`config.php`](config.php) | Hardcoded credentials, database connection, file logger |
| [`cron/daily_report.php`](cron/daily_report.php) + [`crontab`](crontab) | Nightly report written to local disk |
| [`schema.sql`](schema.sql) | Tables created by hand, without a migration history |

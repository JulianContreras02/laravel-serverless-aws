-- The test suite runs against its own database so `migrate:fresh` never touches local data.
CREATE DATABASE IF NOT EXISTS testing;
GRANT ALL PRIVILEGES ON testing.* TO 'shortlinks'@'%';

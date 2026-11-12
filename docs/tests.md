# Tests

```bash
composer install
vendor/bin/phpunit
```

CI runs the suite across PHP 8.2 / 8.3 / 8.4 / 8.5 and Laravel 12 / 13, plus two `--prefer-lowest` rows
that hold the lower bounds of the declared constraints honest. The package is a library, so
`composer.lock` is not committed and CI resolves the dependencies with `composer update` on every
run.

`tests/Unit` and `tests/Feature` need no database: the DDL is generated against a fake partition repository, so
every statement, boundary and name is asserted as a string. The migration layer is covered the same
way — the `Blueprint` is compiled to SQL and asserted, without a connection to a server. The
commands are driven through Symfony's `CommandTester`, so their prompts, tables and exit codes are
asserted too, and `tests/Feature` boots the service provider inside a real application with
`orchestra/testbench` to cover the config merge, the container bindings, the registered commands and
the scheduled maintenance.

`tests/Integration` runs the same operations on a server: it partitions, extends, prunes, truncates
and unpartitions real tables for every period and expression, through the builder and through
migrations, and reads the result back from `information_schema`. CI runs it against MySQL 8.0,
MySQL 8.4 and MariaDB 11.4. Without a server the suite is skipped; point it at one to run it:

```bash
PARTITIONS_DB_HOST=127.0.0.1 PARTITIONS_DB_PASSWORD=secret vendor/bin/phpunit --testsuite Integration
```

`PARTITIONS_DB_PORT` (3306), `PARTITIONS_DB_DATABASE` (`partitions_test`), `PARTITIONS_DB_USERNAME`
(`root`) and `PARTITIONS_DB_DRIVER` (`mysql`, or `mariadb`) are read too. The database has to exist;
the tests create and drop their own tables in it.

PHPUnit 11 is required — the suite uses `#[DataProvider]` attributes, which PHPUnit 9 ignores.

## Static analysis and code style

```bash
composer analyse   # PHPStan (larastan) at level 8 over src
composer lint      # Pint, report only
composer fix       # Pint, rewrite the files
composer test      # PHPUnit
```

PHPStan runs at level 8 with the Larastan extension, so the generics in the annotations
(`Collection<int, PartitionInfo>`, `array{added: ..., dropped: ...}`) are checked against the code
instead of being decoration. Pint uses the `laravel` preset with no overrides. Both run in CI next
to the test matrix, on every push and pull request.

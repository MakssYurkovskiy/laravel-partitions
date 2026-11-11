# Security policy

## Supported versions

| Version | Supported |
| --- | --- |
| 0.1.x | yes |

## Reporting a vulnerability

Please do not open a public issue for a security problem. Report it privately through GitHub:
open the **Security** tab of this repository and choose **Report a vulnerability**. The report is
visible to the maintainer only until a fix is released.

Include what you can of the following: the version of the package, of Laravel and of MySQL or
MariaDB, the builder calls, command or migration involved, and what happens compared to what you
expected. A report about generated DDL is easiest to act on with the statement the package produced
(`dryRun()` or `--dry-run` prints it without running it).

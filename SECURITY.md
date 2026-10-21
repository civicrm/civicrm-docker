# Security

## PHP critical vulnerabilities (CERT-EU 2026-018)

[CERT-EU advisory 2026-018](https://cert.europa.eu/publications/security-advisories/2026-018) tracks two critical PHP vulnerabilities that can allow unauthenticated remote code execution:

| CVE | GHSA | CVSS |
|-----|------|------|
| [CVE-2026-109618](https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w) | GHSA-w8hw-w9fx-j79w | 9.2 |
| [CVE-2026-109619](https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp) | GHSA-6wmf-4rv5-p3rp | 9.2 |

Official CiviCRM Docker images use the [Docker `php` Apache images](https://hub.docker.com/_/php) (`mod_php`). The PHP project considers this setup **highest risk**. Every internet-facing deployment should run a **patched** PHP build.

### Patched PHP versions

Upgrade the PHP runtime to at least:

- PHP 8.5.12
- PHP 8.4.27
- PHP 8.3.36
- PHP 8.2.35

Images are rebuilt from `php:<minor>-apache-trixie` when maintainers run `./build.php`. After upstream publishes patched bases, **pull the newest tag** (for example `docker pull civicrm/civicrm:latest`) or rebuild locally with `./build.php --skip-push`.

`./build.php` refuses to push images whose base PHP is still below these versions unless you pass `--skip-php-patch-check` (emergency use only).

### Mitigations in these images

Mitigations below follow [PHP’s guidance](https://www.php.net/index.php#2026-10-20-1) and CERT-EU 2026-018. **They reduce exposure to known attack paths; only a PHP update fully fixes the bugs.**

| Measure | Addresses | Where |
|---------|-----------|--------|
| `session.upload_progress.enabled = Off` | CVE-2026-109619 (confirmed session paths) | `php-conf-civicrm.ini` and `php_admin_flag` in Apache |
| `post_max_size` / `upload_max_filesize` (64M, upload size below post size) | CVE-2026-109618 (multipart/OOM pressure) | `php-conf-civicrm.ini` |

Additional hardening you may apply in production (especially before patched PHP is available):

- Set a **container memory limit** so runaway workers are killed without relying on PHP’s `memory_limit` longjmp path.
- Optionally set `php_admin_value memory_limit -1` in Apache **only if** no pool, directory, or application overrides it back to a finite limit, and you enforce memory at the container or orchestrator level.
- On PHP 8.5+, set `fatal_error_backtraces=0` and restart workers.

### Verify your running container

```shell
docker compose exec app php -v
```

Compare the reported version with the patched releases above.

## Reporting issues

For vulnerabilities in CiviCRM itself, follow [CiviCRM security](https://civicrm.org/security).

For issues specific to these Docker images, open a GitHub issue in this repository.

# CiviMCP — MCP for CiviCRM

CiviMCP is a CiviCRM extension that lets Claude and other [Model Context Protocol](https://modelcontextprotocol.io) clients work with a CiviCRM site safely.

- **Runs as a real CiviCRM user.** Every tool call goes through `civicrm_api4()` with permission checks, so the AI can only see and do what that user can.
- **Read-only first.** No write tools yet.
- **Labelled.** Every result says which site and environment (Production / Staging / Development) it came from.
- **Redacted and logged.** API keys, hashes and checksums are stripped from results; every call is written to the `civimcp` log channel in ConfigAndLog.

Status: **v0.2 in development** (WordPress, stdio transport). Released: [v0.1.0](https://github.com/gvmathavan/civimcp/releases/tag/v0.1.0).

## Tools

| Tool | What it does |
| --- | --- |
| `site_info` | Versions, site name and URL, environment, components, extensions, current user and key permissions |
| `system_check` | CiviCRM System Status messages at or above a chosen severity |
| `list_entities` | APIv4 entities available to the current user, filterable by keyword |
| `describe_entity` | Fields of an entity, incl. custom fields, labels, links and option values |
| `get` | Read records of any allowed entity with APIv4 `get` (select, where, orderBy, limit ≤ 100, offset), plus totals with groupBy/having and COUNT/SUM/AVG/MIN/MAX |
| `search_contacts` | Find contacts by part of name, email, phone, or ID |
| `list_extensions` | Installed extensions with version and status (enabled, disabled, missing files) |
| `scheduled_jobs` | Scheduled jobs: what each runs, how often, last run, next due; flags never-run, overdue and unfinished jobs |
| `job_log` | Recent job runs and their output, filterable by job, text or date |
| `read_log` | The end of a CiviCRM log file (main or a channel such as `civimcp`), filterable by text |
| `get_setting` | Settings by exact name or keyword, with passwords and keys always redacted |
| `list_saved_searches` | The site's SearchKit saved searches |
| `run_saved_search` | Run a saved search, read-only, with the 100-row cap and permission checks always applied |

The diagnostic tools (extensions, jobs, logs, settings, system check) need the `administer CiviCRM` permission. `read_log` reads files directly, so it checks that permission itself, and it redacts email addresses, keys, passwords and checksums from every line.

Entities that can hold credentials (Setting, PaymentProcessor, OAuth tokens, MailSettings and others) are never readable through these tools.

## Requirements

- CiviCRM 6.3+ on WordPress
- PHP 8.1+ and Composer

## Install (development)

```bash
cd <wordpress>/wp-content/uploads/civicrm/ext/civimcp
composer install
cv en civimcp        # or enable it in Administer > System Settings > Extensions
```

Create a WordPress user for MCP testing with a limited role (it needs at least the `access CiviCRM` permission; `system_check` also needs `administer CiviCRM`).

Set the environment in **Administer > System Settings > Debugging and Error Handling > Environment** so results are labelled correctly.

## Run over stdio

```bash
php bin/mcp-stdio.php --user=<wordpress-login> --url=http://your-site.loc
```

### Try it in MCP Inspector

```bash
npx @modelcontextprotocol/inspector php bin/mcp-stdio.php --user=<wordpress-login> --url=http://your-site.loc
```

### Add to Claude Code

```bash
claude mcp add civimcp -- /path/to/php /path/to/civimcp/bin/mcp-stdio.php --user=<wordpress-login> --url=http://your-site.loc
```

### Add to Claude Desktop

In `~/Library/Application Support/Claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "civimcp": {
      "command": "/Applications/MAMP/bin/php/php8.2.0/bin/php",
      "args": [
        "/path/to/civimcp/bin/mcp-stdio.php",
        "--user=<wordpress-login>",
        "--url=http://your-site.loc"
      ]
    }
  }
}
```

Use the full path to the same PHP your site runs on, so the database connection works.

## Tests

**Unit tests** check CiviMCP's own safety logic (redaction, blocked entities, read-only `get`, row caps, permission errors, tool definitions) against small stand-ins for CiviCRM, so they run in seconds with no database:

```bash
composer require --dev phpunit/phpunit:^10.5   # once
composer test
```

**Smoke test** runs the real server against a real *test* site, as an admin and as a limited user (one with `access CiviCRM` but not `access CiviContribute` or `administer CiviCRM`):

```bash
composer smoke -- --admin=admin --limited=mcp-tester --url=http://your-site.loc
```

**Test data**: `tests/seed/seed-test-data.php` creates ~200 fake contacts with memberships, donations, events and activities on a test site (it refuses to run on a site labelled Production):

```bash
cv scr <path-to>/civimcp/tests/seed/seed-test-data.php
CIVIMCP_SEED=delete cv scr <path-to>/civimcp/tests/seed/seed-test-data.php   # remove it again
```

## Roadmap

v0.1 read-only basics → v0.2 diagnostics (extensions, jobs, logs, settings, SearchKit) → v0.3 safe, previewed writes → v1.0 remote connector over HTTPS with OAuth sign-in → v1.1 chat window inside CiviCRM.

## Licence

MIT. CiviCRM is a registered trademark of CIVICRM LLC. CiviMCP is an independent project, not made or endorsed by the CiviCRM project.

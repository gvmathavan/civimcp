# CiviMCP — MCP for CiviCRM

CiviMCP is a CiviCRM extension that lets Claude and other [Model Context Protocol](https://modelcontextprotocol.io) clients work with a CiviCRM site safely.

- **Runs as a real CiviCRM user.** Every tool call goes through `civicrm_api4()` with permission checks, so the AI can only see and do what that user can.
- **Read-only first.** v0.1 has no write tools.
- **Labelled.** Every result says which site and environment (Production / Staging / Development) it came from.
- **Redacted and logged.** API keys, hashes and checksums are stripped from results; every call is written to the `civimcp` log channel in ConfigAndLog.

Status: **v0.1 in development** (WordPress, stdio transport).

## Tools

| Tool | What it does |
| --- | --- |
| `site_info` | Versions, site name and URL, environment, components, extensions, current user and key permissions |
| `system_check` | CiviCRM System Status messages at or above a chosen severity |
| `list_entities` | APIv4 entities available to the current user, filterable by keyword |
| `describe_entity` | Fields of an entity, incl. custom fields, labels, links and option values |
| `get` | Read records of any allowed entity with APIv4 `get` (select, where, orderBy, limit ≤ 100, offset) |
| `search_contacts` | Find contacts by part of name, email, phone, or ID |

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

## Roadmap

v0.1 read-only basics → v0.2 diagnostics (extensions, jobs, logs, settings, SearchKit) → v0.3 safe, previewed writes → v1.0 remote connector over HTTPS with OAuth sign-in → v1.1 chat window inside CiviCRM.

## Licence

MIT. CiviCRM is a registered trademark of CIVICRM LLC. CiviMCP is an independent project, not made or endorsed by the CiviCRM project.

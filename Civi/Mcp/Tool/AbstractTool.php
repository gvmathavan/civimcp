<?php

namespace Civi\Mcp\Tool;

use Civi\Mcp\Safety\AuditLog;
use Civi\Mcp\Safety\Redactor;
use Civi\Mcp\SiteContext;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Base class for every CiviMCP tool.
 *
 * Each tool is an invokable object: the SDK calls __invoke() with the
 * arguments named in inputSchema(). Inside __invoke(), wrap the work in
 * $this->execute() so it is redacted, labelled with the site context, logged,
 * and turned into a clean error message if it fails.
 */
abstract class AbstractTool {

  /**
   * Tool name Claude sees, e.g. "site_info".
   */
  public const NAME = '';

  /**
   * Human-readable title, e.g. "Site information".
   */
  public const TITLE = '';

  abstract public function description(): string;

  /**
   * JSON Schema for the tool's arguments.
   *
   * @return array<string, mixed>
   */
  abstract public function inputSchema(): array;

  /**
   * Read-only by default. Write tools override this.
   */
  public function annotations(): ToolAnnotations {
    return new ToolAnnotations(
      title: static::TITLE,
      readOnlyHint: TRUE,
      destructiveHint: FALSE,
      idempotentHint: TRUE,
      openWorldHint: FALSE,
    );
  }

  /**
   * Run the tool's work with redaction, site context, audit logging and
   * friendly errors.
   *
   * @param array<string, mixed> $args
   * @param callable(): array<string, mixed> $work
   *
   * @return array<string, mixed>
   */
  protected function execute(array $args, callable $work): array {
    $startedAt = microtime(TRUE);
    try {
      $result = Redactor::redact($work());
      AuditLog::record(static::NAME, $args, 'ok', $startedAt);
      return ['context' => SiteContext::get()] + $result;
    }
    catch (\Civi\API\Exception\UnauthorizedException $e) {
      AuditLog::record(static::NAME, $args, 'denied', $startedAt, $e->getMessage());
      throw new ToolCallException('Permission denied: the CiviCRM user this server runs as is not allowed to do this. ' . $e->getMessage(), 0, $e);
    }
    catch (ToolCallException $e) {
      AuditLog::record(static::NAME, $args, 'error', $startedAt, $e->getMessage());
      throw $e;
    }
    catch (\Throwable $e) {
      AuditLog::record(static::NAME, $args, 'error', $startedAt, $e->getMessage());
      throw new ToolCallException(static::NAME . ' failed: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * JSON Schema for a tool that takes no arguments.
   *
   * @return array<string, mixed>
   */
  protected static function noArguments(): array {
    return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => FALSE];
  }

}

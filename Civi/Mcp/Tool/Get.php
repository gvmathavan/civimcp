<?php

namespace Civi\Mcp\Tool;

use Civi\Mcp\Safety\EntityPolicy;
use Mcp\Exception\ToolCallException;

/**
 * get: read records of any allowed entity with APIv4 "get".
 *
 * Read-only by design: the action is always "get", never create, update,
 * save or delete.
 */
final class Get extends AbstractTool {

  public const NAME = 'get';
  public const TITLE = 'Read records';

  public const DEFAULT_LIMIT = 25;
  public const MAX_LIMIT = 100;

  /**
   * Stop adding rows once the JSON result passes this many characters.
   */
  private const MAX_CHARS = 60000;

  public function description(): string {
    return <<<TXT
Read records from a CiviCRM APIv4 entity (read-only; this tool can never change data). Runs as the current user, so CiviCRM permissions and ACLs apply.

Use describe_entity first to get exact field names. Parameters follow APIv4:
- select: fields to return, e.g. ["id", "display_name", "email_primary.email"]. Joins with dots work, e.g. "contact_id.display_name". Option fields: "status_id:label".
- where: list of conditions, e.g. [["status_id:name", "=", "Current"], ["end_date", "<", "2026-12-01"]]. Operators: =, !=, <, <=, >, >=, LIKE, NOT LIKE, IN, NOT IN, BETWEEN, IS NULL, IS NOT NULL, CONTAINS. Group with ["OR", [[...], [...]]].
- orderBy: e.g. {"end_date": "ASC"}.
- Totals and counts: use groupBy with aggregate functions in select instead of fetching many rows, e.g. select ["status_id:label", "COUNT(id) AS total"], groupBy ["status_id"]; or select ["contact_id.display_name", "SUM(total_amount) AS given"], groupBy ["contact_id"], orderBy {"given": "DESC"}. Functions: COUNT, SUM, AVG, MIN, MAX. Filter groups with having, e.g. [["given", ">", 100]].
- limit (default 25, max 100) and offset for paging. The result says has_more and the total matched.
TXT;
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'entity' => [
          'type' => 'string',
          'description' => 'Entity name, e.g. "Contact", "Membership", "Contribution".',
        ],
        'select' => [
          'type' => 'array',
          'items' => ['type' => 'string'],
          'description' => 'Fields to return. Default: the entity\'s standard fields.',
        ],
        'where' => [
          'type' => 'array',
          'items' => ['type' => 'array'],
          'description' => 'Conditions, e.g. [["contact_type", "=", "Individual"]].',
        ],
        'groupBy' => [
          'type' => 'array',
          'items' => ['type' => 'string'],
          'description' => 'Fields to group by, for totals with COUNT/SUM/AVG/MIN/MAX in select, e.g. ["status_id"].',
        ],
        'having' => [
          'type' => 'array',
          'items' => ['type' => 'array'],
          'description' => 'Conditions on grouped results, e.g. [["total", ">", 5]].',
        ],
        'orderBy' => [
          'type' => 'object',
          'additionalProperties' => ['type' => 'string', 'enum' => ['ASC', 'DESC']],
          'description' => 'Sort order, e.g. {"display_name": "ASC"}.',
        ],
        'limit' => [
          'type' => 'integer',
          'minimum' => 1,
          'maximum' => self::MAX_LIMIT,
          'default' => self::DEFAULT_LIMIT,
          'description' => 'Max records to return (1-100). Default 25.',
        ],
        'offset' => [
          'type' => 'integer',
          'minimum' => 0,
          'default' => 0,
          'description' => 'Skip this many records (for paging).',
        ],
      ],
      'required' => ['entity'],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @param string[] $select
   * @param array<int, mixed> $where
   * @param array<string, string> $orderBy
   * @param string[] $groupBy
   * @param array<int, mixed> $having
   *
   * @return array<string, mixed>
   */
  public function __invoke(string $entity, array $select = [], array $where = [], array $orderBy = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0, array $groupBy = [], array $having = []): array {
    $args = compact('entity', 'select', 'where', 'groupBy', 'having', 'orderBy', 'limit', 'offset');

    return $this->execute($args, function () use ($entity, $select, $where, $groupBy, $having, $orderBy, $limit, $offset): array {
      EntityPolicy::assertReadable($entity);

      foreach ($orderBy as $field => $direction) {
        if (!in_array(strtoupper((string) $direction), ['ASC', 'DESC'], TRUE)) {
          throw new ToolCallException(sprintf('orderBy for "%s" must be ASC or DESC.', $field));
        }
      }

      if ($groupBy && !$select) {
        throw new ToolCallException('When using groupBy, also give select with the grouped fields and aggregates, e.g. ["status_id:label", "COUNT(id) AS total"].');
      }

      $params = [
        'select' => $select ? array_values(array_unique($select)) : ['*'],
        'where' => $where,
        'groupBy' => $groupBy,
        'having' => $having,
        'orderBy' => $orderBy,
      ];
      return ['entity' => $entity] + self::runQuery($entity, $params, $limit, $offset);
    });
  }

  /**
   * Run an APIv4 "get" as the current user and return a small, paginated,
   * size-capped result. Shared by get and run_saved_search.
   *
   * @param array<string, mixed> $params APIv4 get params (select, where, join, groupBy, having, orderBy).
   *
   * @return array{total_matched: ?int, returned: int, offset: int, has_more: bool, next_offset?: int, note?: string, values: array}
   */
  public static function runQuery(string $entity, array $params, int $limit, int $offset): array {
    EntityPolicy::assertReadable($entity);
    $limit = max(1, min(self::MAX_LIMIT, $limit));
    $offset = max(0, $offset);

    $params['select'] = array_values((array) ($params['select'] ?? ['*'])) ?: ['*'];
    // row_count gives the total matched; only database-backed entities support it.
    if (in_array('DAOEntity', (array) (EntityPolicy::entities()[$entity]['type'] ?? []), TRUE) && !in_array('row_count', $params['select'], TRUE)) {
      $params['select'][] = 'row_count';
    }
    // Always read-only, always as the current user.
    $params['limit'] = $limit;
    $params['offset'] = $offset;
    $params['checkPermissions'] = TRUE;

    $result = civicrm_api4($entity, 'get', $params);

    $rows = [];
    $chars = 0;
    $truncatedAt = NULL;
    foreach ($result as $row) {
      unset($row['row_count']);
      $size = strlen((string) json_encode($row));
      if ($rows && $chars + $size > self::MAX_CHARS) {
        $truncatedAt = count($rows);
        break;
      }
      $chars += $size;
      $rows[] = $row;
    }

    try {
      $total = $result->countMatched();
    }
    catch (\Throwable $e) {
      $total = NULL;
    }
    $returned = count($rows);
    $hasMore = $total !== NULL ? ($offset + $returned < $total) : ($returned >= $limit);

    $out = [
      'total_matched' => $total,
      'returned' => $returned,
      'offset' => $offset,
      'has_more' => $hasMore,
    ];
    if ($hasMore) {
      $out['next_offset'] = $offset + $returned;
    }
    if ($truncatedAt !== NULL) {
      $out['note'] = sprintf('Stopped at %d rows to keep the answer small. Select fewer fields or page with offset.', $truncatedAt);
    }
    $out['values'] = $rows;
    return $out;
  }

}

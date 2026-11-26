<?php

namespace Civi\Mcp\Tool;

use Mcp\Exception\ToolCallException;

/**
 * run_saved_search: run a SearchKit saved search and return its rows.
 */
final class RunSavedSearch extends AbstractTool {

  public const NAME = 'run_saved_search';
  public const TITLE = 'Run a saved search';

  public function description(): string {
    return 'Run a SearchKit saved search by its name (from list_saved_searches) and return its rows, read-only, as the current user. Uses the search\'s own fields, filters, joins and grouping; you only choose how many rows (limit, max 100) and paging (offset). The result says how many rows match in total.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'name' => [
          'type' => 'string',
          'description' => 'Saved search name exactly as returned by list_saved_searches.',
        ],
        'limit' => [
          'type' => 'integer',
          'minimum' => 1,
          'maximum' => Get::MAX_LIMIT,
          'default' => 25,
          'description' => 'Max rows to return (1-100). Default 25.',
        ],
        'offset' => [
          'type' => 'integer',
          'minimum' => 0,
          'default' => 0,
          'description' => 'Skip this many rows (for paging).',
        ],
      ],
      'required' => ['name'],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(string $name, int $limit = 25, int $offset = 0): array {
    $args = ['name' => $name, 'limit' => $limit, 'offset' => $offset];

    return $this->execute($args, function () use ($name, $limit, $offset): array {
      $search = \Civi\Api4\SavedSearch::get(TRUE)
        ->addSelect('name', 'label', 'api_entity', 'api_params')
        ->addWhere('name', '=', $name)
        ->execute()
        ->first();
      if (!$search) {
        throw new ToolCallException(sprintf('No saved search named "%s". Call list_saved_searches to see the names.', $name));
      }
      if (empty($search['api_entity'])) {
        throw new ToolCallException(sprintf('Saved search "%s" has no entity to search.', $name));
      }

      // Only the query parts of the search; paging, permissions and the
      // read-only "get" action are always set by Get::runQuery().
      $params = array_intersect_key((array) ($search['api_params'] ?? []), array_flip(['select', 'where', 'join', 'groupBy', 'having', 'orderBy']));

      return [
        'search' => $search['name'],
        'label' => $search['label'] ?? $search['name'],
        'entity' => $search['api_entity'],
      ] + Get::runQuery($search['api_entity'], $params, $limit, $offset);
    });
  }

}

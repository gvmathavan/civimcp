<?php

namespace Civi\Mcp\Tool;

/**
 * list_saved_searches: the site's SearchKit saved searches.
 */
final class ListSavedSearches extends AbstractTool {

  public const NAME = 'list_saved_searches';
  public const TITLE = 'List saved searches';

  public function description(): string {
    return 'List the SearchKit saved searches on this site: name, label, which entity it searches and its description. Saved searches are reports someone has already built and trusted, so prefer running one (run_saved_search) over writing a new query when it answers the question.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'search' => [
          'type' => 'string',
          'description' => 'Optional word to filter by name, label or description, e.g. "lapsed".',
        ],
        'limit' => [
          'type' => 'integer',
          'minimum' => 1,
          'maximum' => 100,
          'default' => 50,
          'description' => 'Max searches to return (1-100). Default 50.',
        ],
      ],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(string $search = '', int $limit = 50): array {
    $args = ['search' => $search, 'limit' => $limit];

    return $this->execute($args, function () use ($search, $limit): array {
      $limit = max(1, min(100, $limit));
      $get = \Civi\Api4\SavedSearch::get(TRUE)
        ->addSelect('id', 'name', 'label', 'api_entity', 'description', 'modified_date', 'row_count')
        ->addOrderBy('label', 'ASC')
        ->setLimit($limit);
      if (trim($search) !== '') {
        $like = '%' . trim($search) . '%';
        $get->addClause('OR', ['name', 'LIKE', $like], ['label', 'LIKE', $like], ['description', 'LIKE', $like]);
      }
      $result = $get->execute();

      $items = [];
      foreach ($result as $row) {
        $items[] = [
          'name' => $row['name'],
          'label' => $row['label'] ?? $row['name'],
          'entity' => $row['api_entity'] ?? NULL,
          'description' => isset($row['description']) ? trim(strip_tags((string) $row['description'])) : NULL,
          'modified' => $row['modified_date'] ?? NULL,
        ];
      }
      try {
        $total = $result->countMatched();
      }
      catch (\Throwable $e) {
        $total = NULL;
      }

      return [
        'total_matched' => $total,
        'returned' => count($items),
        'searches' => $items,
      ];
    });
  }

}

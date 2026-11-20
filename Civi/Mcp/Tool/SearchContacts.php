<?php

namespace Civi\Mcp\Tool;

use Mcp\Exception\ToolCallException;

/**
 * search_contacts: find people and organisations by name, email, phone or ID.
 */
final class SearchContacts extends AbstractTool {

  public const NAME = 'search_contacts';
  public const TITLE = 'Search contacts';

  public function description(): string {
    return 'Find contacts (individuals, organisations, households) by part of their name, email, phone number, or exact contact ID. Deleted contacts (in the trash) are excluded. Returns ID, name, type, primary email, phone and city. Use get with entity "Contact" for anything more detailed.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'query' => [
          'type' => 'string',
          'minLength' => 2,
          'description' => 'Part of a name, email or phone number, or a contact ID, e.g. "smith", "jane@", "07700".',
        ],
        'contact_type' => [
          'type' => 'string',
          'enum' => ['Individual', 'Organization', 'Household'],
          'description' => 'Optional: only this type of contact.',
        ],
        'limit' => [
          'type' => 'integer',
          'minimum' => 1,
          'maximum' => 50,
          'default' => 10,
          'description' => 'Max contacts to return (1-50). Default 10.',
        ],
      ],
      'required' => ['query'],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(string $query, string $contact_type = '', int $limit = 10): array {
    $args = ['query' => $query, 'contact_type' => $contact_type, 'limit' => $limit];

    return $this->execute($args, function () use ($query, $contact_type, $limit): array {
      $query = trim($query);
      if (mb_strlen($query) < 2) {
        throw new ToolCallException('query must be at least 2 characters.');
      }
      $limit = max(1, min(50, $limit));
      $like = '%' . $query . '%';

      $or = [
        ['display_name', 'LIKE', $like],
        ['sort_name', 'LIKE', $like],
        ['email_primary.email', 'LIKE', $like],
        ['phone_primary.phone', 'LIKE', $like],
      ];
      if (ctype_digit($query)) {
        $or[] = ['id', '=', (int) $query];
      }

      $get = \Civi\Api4\Contact::get(TRUE)
        ->addSelect('id', 'display_name', 'contact_type', 'contact_sub_type', 'email_primary.email', 'phone_primary.phone', 'address_primary.city', 'is_deceased', 'row_count')
        ->addWhere('is_deleted', '=', FALSE)
        ->addClause('OR', $or)
        ->addOrderBy('sort_name', 'ASC')
        ->setLimit($limit);
      if ($contact_type !== '') {
        $get->addWhere('contact_type', '=', $contact_type);
      }
      $result = $get->execute();

      $contacts = [];
      foreach ($result as $row) {
        $contacts[] = [
          'id' => $row['id'],
          'display_name' => $row['display_name'],
          'contact_type' => $row['contact_type'],
          'contact_sub_type' => $row['contact_sub_type'] ?: NULL,
          'email' => $row['email_primary.email'] ?? NULL,
          'phone' => $row['phone_primary.phone'] ?? NULL,
          'city' => $row['address_primary.city'] ?? NULL,
          'is_deceased' => (bool) ($row['is_deceased'] ?? FALSE),
        ];
      }

      try {
        $total = $result->countMatched();
      }
      catch (\Throwable $e) {
        $total = NULL;
      }

      return [
        'query' => $query,
        'total_matched' => $total,
        'returned' => count($contacts),
        'contacts' => $contacts,
      ];
    });
  }

}

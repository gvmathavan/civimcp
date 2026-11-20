<?php

namespace Civi\Mcp\Tool;

use Civi\Mcp\Safety\EntityPolicy;

/**
 * describe_entity: the fields of an entity, including custom fields with labels.
 */
final class DescribeEntity extends AbstractTool {

  public const NAME = 'describe_entity';
  public const TITLE = 'Describe an entity';

  /**
   * Show at most this many options per field (e.g. membership statuses).
   */
  private const MAX_OPTIONS = 50;

  public function description(): string {
    return 'Describe the fields of a CiviCRM APIv4 entity: name, label, data type, whether it is required, which entity it links to, and the allowed options (e.g. membership status names). Custom fields are included and appear as "Group_Name.Field_Name". Call this before get so you use the correct field names and option values.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'entity' => [
          'type' => 'string',
          'description' => 'Entity name exactly as returned by list_entities, e.g. "Contact", "Membership".',
        ],
        'search' => [
          'type' => 'string',
          'description' => 'Optional word to filter fields by name or label, e.g. "date" or "status".',
        ],
        'include_options' => [
          'type' => 'boolean',
          'default' => TRUE,
          'description' => 'Include the allowed values for option fields (max 50 per field). Default true.',
        ],
      ],
      'required' => ['entity'],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(string $entity, string $search = '', bool $include_options = TRUE): array {
    $args = ['entity' => $entity, 'search' => $search, 'include_options' => $include_options];

    return $this->execute($args, function () use ($entity, $search, $include_options): array {
      EntityPolicy::assertReadable($entity);

      $fields = civicrm_api4($entity, 'getFields', [
        'action' => 'get',
        'loadOptions' => $include_options,
        'select' => ['name', 'title', 'label', 'description', 'data_type', 'input_type', 'required', 'fk_entity', 'custom_field_id', 'options', 'readonly'],
      ]);

      $needle = mb_strtolower(trim($search));
      $items = [];
      foreach ($fields as $field) {
        $label = $field['label'] ?? $field['title'] ?? $field['name'];
        if ($needle !== '' && !str_contains(mb_strtolower($field['name'] . ' ' . $label), $needle)) {
          continue;
        }
        $item = [
          'name' => $field['name'],
          'label' => $label,
          'data_type' => $field['data_type'] ?? NULL,
        ];
        if (!empty($field['description'])) {
          $item['description'] = trim(strip_tags((string) $field['description']));
        }
        if (!empty($field['required'])) {
          $item['required'] = TRUE;
        }
        if (!empty($field['fk_entity'])) {
          $item['links_to'] = $field['fk_entity'];
        }
        if (!empty($field['custom_field_id'])) {
          $item['custom'] = TRUE;
        }
        if (!empty($field['readonly'])) {
          $item['readonly'] = TRUE;
        }
        if ($include_options && is_array($field['options'] ?? NULL) && $field['options']) {
          $options = $field['options'];
          $item['options'] = array_slice($options, 0, self::MAX_OPTIONS, TRUE);
          if (count($options) > self::MAX_OPTIONS) {
            $item['options_truncated'] = count($options);
          }
          $item['tip'] = 'Filter by option name with "' . $field['name'] . ':name" or by label with "' . $field['name'] . ':label".';
        }
        $items[] = $item;
      }

      return [
        'entity' => $entity,
        'total_fields' => count($items),
        'fields' => $items,
      ];
    });
  }

}

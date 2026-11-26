<?php
/**
 * Minimal stand-ins for the CiviCRM classes and functions CiviMCP calls.
 *
 * Tests configure behaviour through CiviStub's static properties and read
 * back what CiviMCP asked CiviCRM to do (CiviStub::$apiCalls, ::$log).
 * Only for unit tests: never loaded when real CiviCRM is present.
 */

namespace {

  define('CIVICRM_UF', 'WordPress');
  define('CIVICRM_UF_BASEURL', 'http://test.loc/');

  final class CiviStub {

    /** @var array<string, mixed> */
    public static array $settings = [];

    /** @var string[] */
    public static array $permissions = [];

    /** @var array<int, array<string, mixed>> */
    public static array $entities = [];

    /** @var array<int, array<string, mixed>> */
    public static array $systemChecks = [];

    /** @var array<int, array{0: string, 1: string, 2: array}> */
    public static array $apiCalls = [];

    /** @var callable|null fn(string $entity, string $action, array $params): array|StubResult */
    public static $apiHandler = NULL;

    /** @var array<int, array{0: string, 1: array}> */
    public static array $log = [];

    /** Last clauses passed to an Api4 query builder. */
    public static array $lastQuery = [];

    /** @var array<string, array> Rows returned by Api4 builders, keyed by entity (Extension, Job, JobLog, SavedSearch, Setting, SettingFields). */
    public static array $rows = [];

    /** Path returned by CRM_Core_Error::generateLogFileName(). */
    public static string $logFile = '';

    /**
     * Mimic CiviCRM: these entities need "administer CiviCRM".
     */
    public static function requireAdmin(string $what): void {
      if (!in_array('administer CiviCRM', self::$permissions, TRUE)) {
        throw new \Civi\API\Exception\UnauthorizedException("Authorization failed: CiviCRM APIv4 ($what)");
      }
    }

    public static function reset(): void {
      self::$settings = ['environment' => 'Development', 'enable_components' => ['CiviMember', 'CiviEvent']];
      self::$permissions = ['access CiviCRM', 'view all contacts'];
      self::$entities = [
        ['name' => 'Contact', 'title' => 'Contact', 'title_plural' => 'Contacts', 'description' => 'People and organisations', 'type' => ['DAOEntity'], 'primary_key' => ['id']],
        ['name' => 'Membership', 'title' => 'Membership', 'title_plural' => 'Memberships', 'description' => 'Membership records', 'type' => ['DAOEntity'], 'primary_key' => ['id']],
        ['name' => 'Setting', 'title' => 'Setting', 'description' => 'Site settings', 'type' => ['BasicEntity'], 'primary_key' => ['name']],
        ['name' => 'PaymentProcessor', 'title' => 'Payment Processor', 'description' => 'Gateways', 'type' => ['DAOEntity'], 'primary_key' => ['id']],
        ['name' => 'Custom_Extra', 'title' => 'Extra', 'description' => 'Custom group', 'type' => ['CustomValue', 'DAOEntity'], 'primary_key' => ['id']],
        ['name' => 'Basicish', 'title' => 'Basicish', 'description' => 'Not database-backed', 'type' => ['BasicEntity'], 'primary_key' => ['id']],
      ];
      self::$systemChecks = [];
      self::$apiCalls = [];
      self::$apiHandler = NULL;
      self::$log = [];
      self::$lastQuery = [];
      self::$rows = [];
      self::$logFile = '';
      \Civi\Mcp\Safety\EntityPolicy::resetCache();
    }

  }

  final class StubResult extends ArrayObject {

    public function __construct(array $rows, private ?int $matched = NULL) {
      parent::__construct($rows);
    }

    public function first(): ?array {
      return $this->count() ? $this->getArrayCopy()[array_key_first($this->getArrayCopy())] : NULL;
    }

    public function countMatched(): int {
      if ($this->matched === NULL) {
        throw new RuntimeException('countMatched not available');
      }
      return $this->matched;
    }

  }

  function civicrm_api4(string $entity, string $action, array $params = []) {
    CiviStub::$apiCalls[] = [$entity, $action, $params];
    $handler = CiviStub::$apiHandler;
    $result = $handler ? $handler($entity, $action, $params) : [];
    return $result instanceof StubResult ? $result : new StubResult($result);
  }

  final class Civi {

    public static function settings() {
      return new class {

        public function get(string $key) {
          return CiviStub::$settings[$key] ?? NULL;
        }

      };
    }

    public static function log($channel = 'default') {
      return new class {

        public function info($message, array $context = []): void {
          CiviStub::$log[] = [$message, $context];
        }

      };
    }

  }

  final class CRM_Core_Session {

    public static function singleton(): self {
      return new self();
    }

    public static function getLoggedInContactID(): ?int {
      return 2;
    }

    public function getLoggedInContactDisplayName(): string {
      return 'Test User';
    }

  }

  final class CRM_Core_Permission {

    public static function check($permission): bool {
      return in_array($permission, CiviStub::$permissions, TRUE);
    }

  }

  final class CRM_Core_BAO_Domain {

    public static function getDomain(): object {
      return (object) ['name' => 'Test Site'];
    }

  }

  final class CRM_Core_Error {

    public static function generateLogFileName($prefix): string {
      return CiviStub::$logFile;
    }

    /**
     * Records [message, decoded JSON payload, prefix] in CiviStub::$log.
     */
    public static function debug_log_message($message, $out = FALSE, $prefix = '', $priority = NULL): string {
      $json = strpos($message, '{');
      CiviStub::$log[] = [$message, $json !== FALSE ? json_decode(substr($message, $json), TRUE) : NULL, $prefix];
      return $message;
    }

  }

  final class CRM_Utils_System {

    public static function version(): string {
      return '6.3.1';
    }

  }

  final class CRM_Extension_Manager {

    public const STATUS_INSTALLED = 'installed';

    public function getStatuses(): array {
      return ['a' => 'installed', 'b' => 'disabled', 'c' => 'installed'];
    }

  }

  final class CRM_Extension_System {

    public static function singleton(): self {
      return new self();
    }

    public function getManager(): CRM_Extension_Manager {
      return new CRM_Extension_Manager();
    }

  }

}

namespace Civi\API\Exception {

  class UnauthorizedException extends \Exception {}

}

namespace Civi\Api4 {

  /**
   * Records query-builder calls into CiviStub::$lastQuery.
   */
  class StubQuery {

    public function __construct(private \Closure $rows) {
      \CiviStub::$lastQuery = ['where' => [], 'clauses' => [], 'select' => [], 'limit' => NULL, 'includeDisabled' => NULL];
    }

    public function addSelect(...$fields): static {
      \CiviStub::$lastQuery['select'] = array_merge(\CiviStub::$lastQuery['select'], $fields);
      return $this;
    }

    public function addWhere($field, $op, $value = NULL): static {
      \CiviStub::$lastQuery['where'][] = [$field, $op, $value];
      return $this;
    }

    public function addClause($operator, ...$conditions): static {
      \CiviStub::$lastQuery['clauses'][] = [$operator, $conditions];
      return $this;
    }

    public function addOrderBy(...$args): static {
      \CiviStub::$lastQuery['orderBy'][] = $args;
      return $this;
    }

    public function setLimit(int $limit): static {
      \CiviStub::$lastQuery['limit'] = $limit;
      return $this;
    }

    public function setIncludeDisabled(bool $include): static {
      \CiviStub::$lastQuery['includeDisabled'] = $include;
      return $this;
    }

    public function execute() {
      return ($this->rows)();
    }

  }

  class Entity {

    public static function get($checkPermissions = TRUE): StubQuery {
      return new StubQuery(fn() => \CiviStub::$entities);
    }

  }

  class System {

    public static function check(): StubQuery {
      return new StubQuery(function () {
        $min = 0;
        foreach (\CiviStub::$lastQuery['where'] as [$field, $op, $value]) {
          if ($field === 'severity_id' && $op === '>=') {
            $min = $value;
          }
        }
        if (!in_array('administer CiviCRM', \CiviStub::$permissions, TRUE)) {
          throw new \Civi\API\Exception\UnauthorizedException('Authorization failed: CiviCRM APIv4 (System::check)');
        }
        return array_values(array_filter(\CiviStub::$systemChecks, fn($m) => $m['severity_id'] >= $min));
      });
    }

  }

  /**
   * Builder returning CiviStub::$rows[$key], after an optional permission check.
   */
  function stub_rows(string $key, bool $admin): StubQuery {
    return new StubQuery(function () use ($key, $admin) {
      if ($admin) {
        \CiviStub::requireAdmin($key . '::get');
      }
      $rows = \CiviStub::$rows[$key] ?? [];
      return new \StubResult($rows, count($rows));
    });
  }

  class Extension {

    public static function get($checkPermissions = TRUE): StubQuery {
      return stub_rows('Extension', TRUE);
    }

  }

  class Job {

    public static function get($checkPermissions = TRUE): StubQuery {
      return stub_rows('Job', TRUE);
    }

  }

  class JobLog {

    public static function get($checkPermissions = TRUE): StubQuery {
      return stub_rows('JobLog', TRUE);
    }

  }

  class SavedSearch {

    public static function get($checkPermissions = TRUE): StubQuery {
      return new StubQuery(function () {
        $rows = \CiviStub::$rows['SavedSearch'] ?? [];
        foreach (\CiviStub::$lastQuery['where'] as [$field, $op, $value]) {
          if ($field === 'name' && $op === '=') {
            $rows = array_values(array_filter($rows, fn($r) => $r['name'] === $value));
          }
        }
        return new \StubResult($rows, count($rows));
      });
    }

  }

  class Setting {

    public static function get($checkPermissions = TRUE): StubQuery {
      return new StubQuery(function () {
        \CiviStub::requireAdmin('Setting::get');
        $values = \CiviStub::$rows['Setting'] ?? [];
        $rows = [];
        foreach (\CiviStub::$lastQuery['select'] as $name) {
          $rows[] = ['name' => $name, 'value' => $values[$name] ?? NULL];
        }
        return new \StubResult($rows);
      });
    }

    public static function getFields($checkPermissions = TRUE): StubQuery {
      return stub_rows('SettingFields', FALSE);
    }

  }

  class Contact {

    public static function get($checkPermissions = TRUE): StubQuery {
      return new StubQuery(fn() => new \StubResult([
        ['id' => 7, 'display_name' => 'Jane Smith', 'contact_type' => 'Individual', 'contact_sub_type' => NULL, 'email_primary.email' => 'jane@example.org', 'phone_primary.phone' => NULL, 'address_primary.city' => 'Bristol', 'is_deceased' => FALSE, 'row_count' => 1],
      ], 1));
    }

  }

}

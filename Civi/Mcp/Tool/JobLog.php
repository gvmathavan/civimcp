<?php

namespace Civi\Mcp\Tool;

use Civi\Mcp\Safety\Redactor;

/**
 * job_log: recent output of scheduled job runs.
 */
final class JobLog extends AbstractTool {

  public const NAME = 'job_log';
  public const TITLE = 'Scheduled job log';

  private const MAX_DATA_CHARS = 1500;

  public function description(): string {
    return 'Show recent scheduled job runs from the job log, newest first: when each ran, which job, the command and its output or error message. Filter by job ID or name (from scheduled_jobs), or by a word in the output such as "error" or "failed". Emails, keys and passwords in the output are redacted. Requires the "administer CiviCRM" permission.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'job_id' => [
          'type' => 'integer',
          'description' => 'Only runs of this job (ID from scheduled_jobs).',
        ],
        'job_name' => [
          'type' => 'string',
          'description' => 'Only runs of jobs whose name contains this text, e.g. "membership".',
        ],
        'search' => [
          'type' => 'string',
          'description' => 'Only runs whose output or description contains this text, e.g. "error".',
        ],
        'since' => [
          'type' => 'string',
          'description' => 'Only runs on or after this date/time, e.g. "2026-11-01" or "-7 days".',
        ],
        'limit' => [
          'type' => 'integer',
          'minimum' => 1,
          'maximum' => 100,
          'default' => 20,
          'description' => 'Max runs to return (1-100). Default 20.',
        ],
      ],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function __invoke(?int $job_id = NULL, string $job_name = '', string $search = '', string $since = '', int $limit = 20): array {
    $args = compact('job_id', 'job_name', 'search', 'since', 'limit');

    return $this->execute($args, function () use ($job_id, $job_name, $search, $since, $limit): array {
      $limit = max(1, min(100, $limit));
      $get = \Civi\Api4\JobLog::get(TRUE)
        ->addSelect('id', 'run_time', 'job_id', 'name', 'command', 'description', 'data')
        ->addOrderBy('run_time', 'DESC')
        ->addOrderBy('id', 'DESC')
        ->setLimit($limit);
      if ($job_id) {
        $get->addWhere('job_id', '=', $job_id);
      }
      if (trim($job_name) !== '') {
        $get->addWhere('name', 'LIKE', '%' . trim($job_name) . '%');
      }
      if (trim($search) !== '') {
        $like = '%' . trim($search) . '%';
        $get->addClause('OR', ['description', 'LIKE', $like], ['data', 'LIKE', $like]);
      }
      if (trim($since) !== '') {
        $time = strtotime(trim($since));
        if ($time === FALSE) {
          throw new \Mcp\Exception\ToolCallException(sprintf('Could not understand since="%s". Use a date like "2026-11-01" or "-7 days".', $since));
        }
        $get->addWhere('run_time', '>=', date('Y-m-d H:i:s', $time));
      }

      $runs = [];
      foreach ($get->execute() as $row) {
        $data = Redactor::redactText((string) ($row['data'] ?? ''));
        if (mb_strlen($data) > self::MAX_DATA_CHARS) {
          $data = mb_substr($data, 0, self::MAX_DATA_CHARS) . ' …[truncated]';
        }
        $runs[] = [
          'run_time' => $row['run_time'] ?? NULL,
          'job_id' => $row['job_id'] ?? NULL,
          'job' => $row['name'] ?? NULL,
          'command' => Redactor::redactText((string) ($row['command'] ?? '')),
          'description' => Redactor::redactText((string) ($row['description'] ?? '')),
          'output' => $data,
        ];
      }

      return [
        'returned' => count($runs),
        'runs' => $runs,
      ];
    });
  }

}

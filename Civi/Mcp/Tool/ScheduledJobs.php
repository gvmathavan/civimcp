<?php

namespace Civi\Mcp\Tool;

use Civi\Mcp\Safety\Redactor;

/**
 * scheduled_jobs: CiviCRM scheduled jobs, how often they run and when they last ran.
 */
final class ScheduledJobs extends AbstractTool {

  public const NAME = 'scheduled_jobs';
  public const TITLE = 'Scheduled jobs';

  public function description(): string {
    return 'List CiviCRM scheduled jobs (Administer > System Settings > Scheduled Jobs): name, the API call each runs, how often, when it last started and finished, and when it is next due. Flags active jobs that have never run or are overdue. Use this to answer "is cron running?" or "why did the membership status update not happen?". Use job_log for a job\'s recent output. Requires the "administer CiviCRM" permission.';
  }

  public function inputSchema(): array {
    return [
      'type' => 'object',
      'properties' => [
        'include_inactive' => [
          'type' => 'boolean',
          'default' => FALSE,
          'description' => 'Also list disabled jobs. Default false.',
        ],
      ],
      'additionalProperties' => FALSE,
    ];
  }

  /**
   * Allowed gap between runs, in seconds, before an active job counts as overdue.
   */
  private const OVERDUE_AFTER = [
    'Always' => 2 * 3600,
    'Hourly' => 3 * 3600,
    'Daily' => 2 * 86400,
    'Weekly' => 8 * 86400,
    'Monthly' => 32 * 86400,
    'Quarter' => 93 * 86400,
    'Yearly' => 367 * 86400,
  ];

  /**
   * @return array<string, mixed>
   */
  public function __invoke(bool $include_inactive = FALSE): array {
    $args = ['include_inactive' => $include_inactive];

    return $this->execute($args, function () use ($include_inactive): array {
      $get = \Civi\Api4\Job::get(TRUE)
        ->addSelect('id', 'name', 'description', 'api_entity', 'api_action', 'parameters', 'run_frequency', 'is_active', 'last_run', 'last_run_end', 'scheduled_run_date')
        ->addOrderBy('name', 'ASC');
      if (!$include_inactive) {
        $get->addWhere('is_active', '=', TRUE);
      }

      $now = time();
      $jobs = [];
      $problems = 0;
      foreach ($get->execute() as $job) {
        $lastRun = $job['last_run'] ?? NULL;
        $flags = [];
        if (!empty($job['is_active'])) {
          if (!$lastRun) {
            $flags[] = 'never run';
          }
          elseif (($limit = self::OVERDUE_AFTER[$job['run_frequency'] ?? ''] ?? NULL) && $now - strtotime($lastRun) > $limit) {
            $flags[] = 'overdue';
          }
          if ($lastRun && !empty($job['last_run_end']) && strtotime($job['last_run_end']) < strtotime($lastRun)) {
            $flags[] = 'last run did not finish';
          }
        }
        $problems += $flags ? 1 : 0;
        $jobs[] = [
          'id' => $job['id'],
          'name' => $job['name'],
          'api_call' => ($job['api_entity'] ?? '') . '.' . ($job['api_action'] ?? ''),
          'frequency' => $job['run_frequency'] ?? NULL,
          'active' => (bool) ($job['is_active'] ?? FALSE),
          'last_run' => $lastRun,
          'last_run_end' => $job['last_run_end'] ?? NULL,
          'next_due' => $job['scheduled_run_date'] ?? NULL,
          'parameters' => isset($job['parameters']) && $job['parameters'] !== '' ? Redactor::redactText((string) $job['parameters']) : NULL,
          'flags' => $flags,
        ];
      }

      return [
        'total' => count($jobs),
        'jobs_with_problems' => $problems,
        'jobs' => $jobs,
      ];
    });
  }

}

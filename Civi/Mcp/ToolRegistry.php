<?php

namespace Civi\Mcp;

use Civi\Mcp\Tool\AbstractTool;
use Civi\Mcp\Tool\DescribeEntity;
use Civi\Mcp\Tool\Get;
use Civi\Mcp\Tool\ListEntities;
use Civi\Mcp\Tool\SearchContacts;
use Civi\Mcp\Tool\SiteInfo;
use Civi\Mcp\Tool\GetSetting;
use Civi\Mcp\Tool\JobLog;
use Civi\Mcp\Tool\ListExtensions;
use Civi\Mcp\Tool\ListSavedSearches;
use Civi\Mcp\Tool\ReadLog;
use Civi\Mcp\Tool\RunSavedSearch;
use Civi\Mcp\Tool\ScheduledJobs;
use Civi\Mcp\Tool\SystemCheck;

/**
 * The list of tools CiviMCP exposes. Add new tools here.
 */
final class ToolRegistry {

  /**
   * @return AbstractTool[]
   */
  public static function tools(): array {
    return [
      new SiteInfo(),
      new SystemCheck(),
      new ListEntities(),
      new DescribeEntity(),
      new Get(),
      new SearchContacts(),
      // v0.2 diagnostics.
      new ListExtensions(),
      new ScheduledJobs(),
      new JobLog(),
      new ReadLog(),
      new GetSetting(),
      new ListSavedSearches(),
      new RunSavedSearch(),
    ];
  }

}

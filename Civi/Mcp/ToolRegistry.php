<?php

namespace Civi\Mcp;

use Civi\Mcp\Tool\AbstractTool;
use Civi\Mcp\Tool\DescribeEntity;
use Civi\Mcp\Tool\Get;
use Civi\Mcp\Tool\ListEntities;
use Civi\Mcp\Tool\SearchContacts;
use Civi\Mcp\Tool\SiteInfo;
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
    ];
  }

}

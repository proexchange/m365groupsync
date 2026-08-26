<?php

namespace Civi\Api4\Action\M365GroupSync;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Trusted scheduled-job entry point for the shared queue worker.
 */
class Scheduled extends AbstractAction {

  public function _run(Result $result): void {
    $result[] = (new \CRM_M365GroupSync_Service_ScheduledJob())->run();
  }

}

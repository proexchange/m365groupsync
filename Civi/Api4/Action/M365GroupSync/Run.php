<?php

namespace Civi\Api4\Action\M365GroupSync;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

class Run extends AbstractAction {

  /**
   * CiviCRM Group ID.
   *
   * @var int
   * @required
   */
  protected $groupId;

  /**
   * Synchronization mode.
   *
   * @var string
   * @options compare,dry_run,sync
   */
  protected $mode = 'compare';

  public function _run(Result $result): void {
    $groupId = (int) $this->groupId;
    if ($groupId <= 0) {
      throw new \CRM_Core_Exception('groupId must be a positive integer.');
    }
    if (!in_array($this->mode, ['compare', 'dry_run', 'sync'], TRUE)) {
      throw new \CRM_Core_Exception('mode must be compare, dry_run, or sync.');
    }
    $m365GroupId = \CRM_M365GroupSync_Service_Mapping::getM365Id($groupId);
    if ($m365GroupId === '') {
      throw new \CRM_Core_Exception('CiviCRM Group is not mapped.');
    }
    $result[] = (new \CRM_M365GroupSync_Service_Sync())->run($groupId, $m365GroupId, $this->mode);
  }

}

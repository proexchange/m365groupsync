<?php

namespace Civi\Api4\Action\M365GroupSync;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

class Start extends AbstractAction {

  /**
   * Queue mode.
   *
   * @var string
   * @required
   * @options dry_run,sync
   */
  protected $mode;

  /**
   * Optional CiviCRM Group ID; omit to queue every mapped group.
   *
   * @var int|null
   */
  protected $groupId;

  public function _run(Result $result): void {
    if (!in_array($this->mode, ['dry_run', 'sync'], TRUE)) {
      throw new \CRM_Core_Exception('mode must be dry_run or sync.');
    }
    $mappings = \CRM_M365GroupSync_Service_Mapping::all();
    if ($this->groupId !== NULL) {
      $groupId = (int) $this->groupId;
      if ($groupId <= 0) {
        throw new \CRM_Core_Exception('groupId must be a positive integer when provided.');
      }
      $mappings = array_values(array_filter(
        $mappings,
        static fn(array $mapping): bool => (int) $mapping['civicrm_group_id'] === $groupId
      ));
    }
    if (!$mappings) {
      throw new \CRM_Core_Exception('No mapped groups were found.');
    }
    $result[] = (new \CRM_M365GroupSync_Service_Sync())->startMany($mappings, $this->mode, 'manual');
  }

}

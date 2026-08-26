<?php

namespace Civi\Api4\Action\M365GroupSync;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

class Process extends AbstractAction {

  /**
   * Durable operation identifier.
   *
   * @var string
   * @required
   */
  protected $operationId;

  public function _run(Result $result): void {
    $operationId = trim((string) $this->operationId);
    if ($operationId === '') {
      throw new \CRM_Core_Exception('operationId is required.');
    }
    $result[] = (new \CRM_M365GroupSync_Service_Sync())->processOperation($operationId);
  }

}

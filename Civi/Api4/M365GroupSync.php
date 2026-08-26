<?php

namespace Civi\Api4;

use Civi\Api4\Action\M365GroupSync\Cancel;
use Civi\Api4\Action\M365GroupSync\Process;
use Civi\Api4\Action\M365GroupSync\Run;
use Civi\Api4\Action\M365GroupSync\Scheduled;
use Civi\Api4\Action\M365GroupSync\Start;
use Civi\Api4\Action\M365GroupSync\Status;
use Civi\Api4\Generic\AbstractEntity;
use Civi\Api4\Generic\BasicGetFieldsAction;

/**
 * Administrative Microsoft 365 Group synchronization operations.
 *
 * This is intentionally a non-DAO entity with no generic CRUD actions.
 */
class M365GroupSync extends AbstractEntity {

  public static function getFields($checkPermissions = TRUE): BasicGetFieldsAction {
    return (new BasicGetFieldsAction(static::getEntityName(), __FUNCTION__, static fn(): array => []))
      ->setCheckPermissions($checkPermissions);
  }

  public static function run($checkPermissions = TRUE): Run {
    return (new Run(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function start($checkPermissions = TRUE): Start {
    return (new Start(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function process($checkPermissions = TRUE): Process {
    return (new Process(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function status($checkPermissions = TRUE): Status {
    return (new Status(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function cancel($checkPermissions = TRUE): Cancel {
    return (new Cancel(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  /**
   * Process queued work and automatic reconciliation from CiviCRM cron.
   */
  public static function scheduled($checkPermissions = TRUE): Scheduled {
    return (new Scheduled(static::getEntityName(), __FUNCTION__))
      ->setCheckPermissions($checkPermissions);
  }

  public static function permissions(): array {
    return [
      'default' => [\M365GROUPSYNC_ADMIN_PERMISSION],
    ];
  }

}

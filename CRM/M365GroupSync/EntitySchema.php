<?php

/** Shared field declarations used by the extension entity metadata. */
class CRM_M365GroupSync_EntitySchema {
  public static function domainField(): array {
    return [
      'title' => ts('Domain'), 'sql_type' => 'int unsigned', 'input_type' => 'EntityRef', 'required' => TRUE,
      'default_callback' => ['CRM_Core_BAO_Domain', 'getDomainID'],
      'entity_reference' => ['entity' => 'Domain', 'key' => 'id'],
    ];
  }

  public static function referenceField(string $title, string $entity, string $key, bool $required, string $type = 'int unsigned'): array {
    return [
      'title' => $title, 'sql_type' => $type, 'input_type' => 'EntityRef', 'required' => $required,
      'entity_reference' => ['entity' => $entity, 'key' => $key],
    ];
  }
}

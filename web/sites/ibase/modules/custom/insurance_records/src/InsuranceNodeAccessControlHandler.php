<?php

declare(strict_types=1);

namespace Drupal\insurance_records;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeAccessControlHandler;

/**
 * Node access handler that keeps insurance policies private.
 *
 * Core's handler returns "allowed" for "bypass node access" before any
 * hook_node_access() implementation runs, so the module's hook alone can't
 * stop such a user. This handler checks "view insurance records" first, for
 * every operation including create.
 *
 * @see insurance_records_entity_type_alter()
 */
class InsuranceNodeAccessControlHandler extends NodeAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  public function access(EntityInterface $entity, $operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account = $this->prepareUser($account);
    if ($result = $this->insuranceForbidden($entity->bundle(), $account)) {
      return $return_as_object ? $result : $result->isAllowed();
    }
    return parent::access($entity, $operation, $account, $return_as_object);
  }

  /**
   * {@inheritdoc}
   */
  public function createAccess($entity_bundle = NULL, ?AccountInterface $account = NULL, array $context = [], $return_as_object = FALSE) {
    $account = $this->prepareUser($account);
    if ($entity_bundle && ($result = $this->insuranceForbidden($entity_bundle, $account))) {
      return $return_as_object ? $result : $result->isAllowed();
    }
    return parent::createAccess($entity_bundle, $account, $context, $return_as_object);
  }

  /**
   * Returns "forbidden" for insurance bundles without the permission.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   A forbidden result, or NULL when the other access rules decide.
   */
  protected function insuranceForbidden(string $bundle, AccountInterface $account): ?AccessResult {
    if (in_array($bundle, INSURANCE_RECORDS_NODE_BUNDLES, TRUE) && !$account->hasPermission('view insurance records')) {
      return AccessResult::forbidden('The "view insurance records" permission is required.')->cachePerPermissions();
    }
    return NULL;
  }

}

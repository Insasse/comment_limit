<?php

namespace Drupal\comment_limit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\field\Entity\FieldConfig;

/**
 * Class CommentLimitQuery.
 *
 * @package Drupal\comment_limit
 */
class CommentLimit {

  /**
   * The user object.
   *
   * @var AccountProxyInterface $user
   */
  protected $user;

  /**
   * Database connection.
   *
   * @var Connection $database
   */
  protected $database;

  /**
   * Message to show.
   *
   * @var string $message
   */
  protected $message;

  /**
   * Constructor.
   */
  public function __construct(Connection $database, AccountProxyInterface $user) {
    $this->database = $database;
    $this->user = $user;
  }

  /**
   * Get user comment limit for this user.
   *
   * @param int $entity_id
   *   The ID of the current entity.
   * @param string $entity_type
   *   Current entity type.
   *
   * @return int
   *    Current count of comments the user has made on an entity.
   */
  public function getCurrentCommentCountForUser($entity_id, $entity_type) {
    // Count comment of user.
    $query = $this->database->select('comment_field_data', 'c')
      ->fields('c', ['entity_id', 'uid'])
      ->condition('uid', $this->user->id())
      ->condition('entity_id', $entity_id)
      ->condition('entity_type', $entity_type)
      ->execute();
    $query->allowRowCount = TRUE;
    return $query->rowCount();
  }

  /**
   * Get node comment limit for this entity.
   *
   * @param int $entity_id
   *   The ID of the current entity.
   * @param string $entity_type
   *   Current entity type.
   *
   * @return int
   *    Current count of comments that were made on an entity.
   */
  public function getCurrentCommentsOnEntity($entity_id, $entity_type) {
    $query = $this->database->select('comment_entity_statistics', 'c')
      ->fields('c', ['comment_count'])
      ->condition('entity_id', $entity_id)
      ->condition('entity_type', $entity_type)
      ->execute()
      ->fetchField();
    return $query;
  }

  /**
   * Get the comment limit of the entity.
   *
   * @param int $entity_id
   *   The ID of the current entity.
   * @param string $entity_type
   *   Current entity type.
   *
   * @return mixed|null
   *   Returns the comment limit of the entity.
   */
  public function getFieldLimit($field_id) {
    $commentLimit = $this->getFieldConfig($field_id);
    return $commentLimit->getThirdPartySetting('comment_limit', 'entity_limit', FALSE);
  }

  /**
   * Get the comment limit for the user.
   *
   * @param int $entity_id
   *   The ID of the current entity.
   * @param string $entity_type
   *   Current entity type.
   *
   * @return mixed|null
   *   Returns the comment limit for the user.
   */
  public function getUserLimit($field_id) {
    $commentLimit = $this->getFieldConfig($field_id);
    return $commentLimit->getThirdPartySetting('comment_limit', 'user_limit', FALSE);
  }

  /**
   * Has the user reached his/her comment limit.
   *
   * @param int $entity_id
   *   The ID of the current entity.
   * @param string $entity_type
   *   Current entity type.
   *
   * @return bool
   *    Returns TRUE or FALSE.
   */
  public function hasUserLimitReached($entity_id, $entity_type, $field_id) {
    if ($this->getCurrentCommentCountForUser($entity_id, $entity_type) >= $this->getUserLimit($field_id) && !$this->user->hasPermission('bypass comment limit')) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Has the comment limit for the entity been reached.
   *
   * @param int $entity_id
   *    The ID of the current entity.
   * @param string $entity_type
   *   Current entity type.
   *
   * @return bool
   *    Returns TRUE or FALSE.
   */
  public function hasFieldLimitReached($entity_id, $entity_type, $field_id) {
    if ($this->getCurrentCommentsOnEntity($entity_id, $entity_type) >= $this->getFieldLimit($field_id) && !$this->user->hasPermission('bypass comment limit')) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Get all ContentEntityTypes.
   *
   * @return array entity types
   *    Get an array of all ContentEntities.
   */
  public function getAllEntityTypes() {
    // Get all entities.
    $entity_types = \Drupal::entityTypeManager()->getDefinitions();
    $content_entity_types = array_filter($entity_types, function ($entity_type) {
      return $entity_type instanceof ContentEntityTypeInterface;
    });
    $content_entity_type_ids = array_keys($content_entity_types);
    return $content_entity_type_ids;
  }

  /**
   * Get the right error message.
   *
   * @param int $entity_id
   *   The entity id.
   * @param string $entity_type
   *   The entity type.
   * @param string $field_id
   *   The field id.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Returns translateable markup with the correct error message.
   */
  public function getMessage($entity_id, $entity_type, $field_id) {
    if (
      $this->getUserLimit($field_id) &&
      $this->getFieldLimit($field_id)
    ) {
      if (
        $this->hasFieldLimitReached($entity_id, $entity_type, $field_id) &&
        $this->hasUserLimitReached($entity_id, $entity_type, $field_id)
      ) {
        return $this->message = t('The comment limits for this @field and your limit were reached', ['@field' => $field_id]);
      }
    }
    if ($this->getFieldLimit($field_id)) {
      if ($this->hasFieldLimitReached($entity_id, $entity_type, $field_id)) {
        return $this->message = t('The comment limit for this @field was reached', ['@field' => $field_id]);
      }
    }
    if ($this->getUserLimit($field_id)) {
      if ($this->hasUserLimitReached($entity_id, $entity_type, $field_id)) {
        return $this->message = t('You have reached your comment limit for this @field', ['@field' => $field_id]);
      }
    }
  }

  /**
   * Get the field ids of  specific field type
   *
   * @param array $fields
   *    An array of field definitions.
   * @param $field_type
   *    The field type to select.
   *
   * @return array $field_ids
   *    Returns an array of field ids.
   */
  public function getFieldIdsOfType(array $fields, $field_type) {
    $field_ids = [];

    foreach ($fields as $field) {
      if ($field->getType() == $field_type) {
        $field_ids[$field->id()] = $field->id();
      }
    }

    return $field_ids;
  }

  /**
   * Get the FieldConfig of a comment field used in a specific entity bundle.
   *
   * @param string $field_id
   *   Current field_id.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null|static
   *    Returns the FieldConfig object.
   */
  private function getFieldConfig($field_id) {
      $field_config = FieldConfig::load($field_id);
    return $field_config;
  }

}

<?php

namespace Drupal\mespronos\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Entity\ContentEntityBase;

class Reminder extends ContentEntityBase {

  /**
   * Defines the Reminder entity.
   *
   * @ingroup mespronos
   *
   * @ContentEntityType(
   *   id = "mespronos_reminder",
   *   label = @Translation("Reminder entity"),
   *   base_table = "mespronos__reminder",
   *   admin_permission = "administer Reminder entity",
   *   fieldable = FALSE,
   *   entity_keys = {
   *     "id" = "id",
   *     "label" = "name",
   *     "uuid" = "uuid"
   *   }
   * )
   */
  public static function preCreate(EntityStorageInterface $storage_controller, array &$values) {
    parent::preCreate($storage_controller, $values);
  }

  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = [];

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('ID'))
      ->setDescription(t('The ID of the entity.'))
      ->setReadOnly(TRUE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setDescription(t('The UUID of the entity.'))
      ->setReadOnly(TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the entity was created.'));

    $fields['day'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Day'))
      ->setDescription(t('Day entity reference'))
      ->setRevisionable(TRUE)
      ->setSetting('target_type', 'day')
      ->setSetting('handler', 'default')
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'entity_reference',
        'weight' => 0,
      ))
      ->setDisplayOptions('form', array(
        'type' => 'options_select',
        'weight' => -1,
        'settings' => array(),
      ))
      ->setDisplayConfigurable('form', false)
      ->setDisplayConfigurable('view', TRUE);

    $fields['hour'] = BaseFieldDefinition::create('integer')
      ->setLabel('Reminder hour before')
      ->setRevisionable(TRUE)
      ->setSetting('unsigned', TRUE)
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'integer',
        'weight' => 6,
      ))
      ->setDisplayOptions('form', array(
        'type' => 'number',
        'weight' => 6,
      ));

    $fields['emails_sended'] = BaseFieldDefinition::create('integer')
      ->setLabel('Number of sended emails')
      ->setRevisionable(TRUE)
      ->setSetting('unsigned', TRUE)
      ->setDisplayOptions('view', array(
        'label' => 'hidden',
        'type' => 'integer',
        'weight' => 6,
      ))
      ->setDisplayOptions('form', array(
        'type' => 'number',
        'weight' => 6,
      ));

    return $fields;
  }

}
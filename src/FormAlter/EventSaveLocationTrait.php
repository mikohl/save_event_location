<?php

namespace Drupal\save_event_location\FormAlter;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepositoryInterface;
use Drupal\address\Plugin\Field\FieldType\AddressFieldItemList;
use Drupal\address\Plugin\Field\FieldType\AddressItem;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\Entity\Node;
use Drupal\node\NodeStorageInterface;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Helper logic for saving and reusing event locations.
 */
trait EventSaveLocationTrait {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The address format repository.
   *
   * @var \CommerceGuys\Addressing\AddressFormat\AddressFormatRepositoryInterface
   */
  private AddressFormatRepositoryInterface $addressFormatRepository;

  /**
   * Validate that address is filled if user tries to save event location.
   */
  public function validateLocationAddress(array &$form, FormStateInterface $form_state): void {
    $save_address = (boolean) $form_state->getValue('save_location_address');
    $address_field_name = $this->addressFieldName($form_state);
    $event_location_address = $form_state->getValue($address_field_name) ?: FALSE;
    if ($save_address && (!$event_location_address || (count(array_filter($event_location_address[0]['address'])) < 3))) {
      $form_state->setErrorByName($address_field_name, $this->t('The address is required if "Remember this address?" is checked.'));
    }
  }

  /**
   * Custom submit callback for event instance entity form.
   *
   * @param array $form
   *   The form definition.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function saveEventLocation(array $form, FormStateInterface $form_state): void {
    $save_address = (boolean) $form_state->getValue('save_location_address', 0);
    if (!$save_address) {
      return;
    }
    $entity = $form_state->getFormObject()->getEntity();
    $location_field_name = $this->locationTypeFieldName($form_state);
    if ($entity->get($location_field_name)->value !== 'other') {
      return;
    }

    $address_field_name = $this->addressFieldName($form_state);
    if ($entity->get($address_field_name)->isEmpty()) {
      return;
    }

    $address_list = $entity->get($address_field_name);
    if ($this->addressAlreadyExists($entity->get($address_field_name))) {
      return;
    }

    $new_location = $this->nodeStorage()
      ->create([
        'type' => 'event_location',
        'title' => $this->eventLocationTitle($address_list->first()),
        'status' => 1,
        'field_address' => $address_list,
      ]);
    $new_location->save();
  }

  /**
   * Creates dropdown field with list of saved event locations.
   *
   * @return array
   *   Field details for Saved Location Field dropdown.
   */
  public function savedEventLocationField(): array {
    // Pull saved event_location nodes and return the addresses as values.
    $nids = $this->nodeStorage()
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event_location')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();
    if (empty($nids)) {
      return [];
    }
    // Add Placeholder.
    $saved_location_options['_none'] = $this->t('Select address');
    $event_location_nodes = Node::loadMultiple($nids);
    foreach ($event_location_nodes as $node) {
      $saved_location_options[$node->id()] = $node->label();
    }

    // Return render array with select field.
    return [
      '#type' => 'select',
      '#title' => $this->t('Previously used location'),
      '#options' => $saved_location_options,
      '#description' => $this->t('Select previously used location to autofill address fields'),
      '#ajax' => [
        'callback' => [$this, 'populateSavedAddress'],
        'event' => 'change',
      ],
    ];
  }

  /**
   * Creates checkbox field to flag address to be saved.
   *
   * @return array
   *   Field details for Saved Location Field dropdown.
   */
  public function rememberAddressField(): array {
    return [
      '#type' => 'checkbox',
      '#title' => $this->t('Remember this address?'),
      '#description' => $this->t("Check this box if you'd like to reuse this address when creating events in the future."),
      '#required' => FALSE,
    ];
  }

  /**
   * Ajax callback to update the address form if user selects saved address.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax response with saved address.
   */
  public function populateSavedAddress(array &$form, FormStateInterface $form_state): AjaxResponse {
    $selected_event_location_id = $form_state->getValue('saved_event_locations');
    $response = new AjaxResponse();
    $address_field_name = $this->addressFieldName($form_state);
    if ($selected_event_location_id != '_none') {
      $event_location_node = $this->nodeStorage()->load($selected_event_location_id);
      $address = $event_location_node->get('field_address')->getValue();
      foreach (['administrative_area', 'locality', 'postal_code', 'address_line1', 'address_line2', 'address_line3'] as $key) {
        $response->addCommand(new InvokeCommand("[name='" . $form[$address_field_name]['widget'][0]['address'][$key]['#name'] . "']", 'val', [$address[0][$key]]));
      }
    }
    else {
      foreach (['administrative_area', 'locality', 'postal_code', 'address_line1', 'address_line2', 'address_line3'] as $key) {
        $response->addCommand(new InvokeCommand("[name='" . $form[$address_field_name]['widget'][0]['address'][$key]['#name'] . "']", 'val', ['']));
      }
    }
    return $response;
  }

  /**
   * Get the address field name based on the form id.
   */
  private function addressFieldName($form_state): string {
    $entity = $form_state->getFormObject()->getEntity();
    return $entity instanceof EventSeries ?
      'field_other_location' :
      'field_events_custom_other_loc';
  }

  /**
   * Get the location type field name based on the form id.
   */
  private function locationTypeFieldName($form_state): string {
    $entity = $form_state->getFormObject()->getEntity();
    return $entity instanceof EventSeries ?
      'field_location' :
      'field_events_custom_location';
  }

  /**
   * Checks if the current address is already in the system.
   *
   * @param \Drupal\address\Plugin\Field\FieldType\AddressFieldItemList $address
   *   The address to compare.
   *
   * @return bool
   *   Whether the address already exists or not
   */
  private function addressAlreadyExists(AddressFieldItemList $address): bool {
    $locations = $this->nodeStorage()
      ->loadByProperties([
        'type' => 'event_location',
      ]);

    // If address is equals to an existing one, skip.
    foreach ($locations as $location) {
      if ($location->field_address->equals($address)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Prepares an Event location title from a given address.
   *
   * @param \Drupal\address\Plugin\Field\FieldType\AddressItem $address
   *   The address.
   *
   * @return string
   *   The event location title
   */
  private function eventLocationTitle(AddressItem $address): string {
    $address_format = $this->addressFormatRepository()->get($address->getCountryCode());
    $address_bits = [];

    // Logic taken from AddressDefaultFormatter.
    $values = [];
    foreach (AddressField::getAll() as $field) {
      $getter = 'get' . ucfirst($field);
      $values[$field] = $address->$getter();
    }

    foreach ($address_format->getUsedFields() as $field) {
      $address_bits['%' . $field] = $values[$field];
    }

    return str_replace("\n", ', ', trim(strip_tags((new FormattableMarkup($address_format->getFormat(), $address_bits))->__toString())));
  }

  /**
   * Shortcut function to get the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  private function entityTypeManager(): EntityTypeManagerInterface {
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
    return $this->entityTypeManager;
  }

  /**
   * Shortcut function to get the node storage.
   *
   * @return \Drupal\node\NodeStorageInterface
   *   The node storage.
   */
  private function nodeStorage(): NodeStorageInterface {
    return $this->entityTypeManager()->getStorage('node');
  }

  /**
   * Shortcut function to get the address format repository.
   *
   * @return \CommerceGuys\Addressing\AddressFormat\AddressFormatRepositoryInterface
   *   The address format repository.
   */
  private function addressFormatRepository(): AddressFormatRepositoryInterface {
    if (!isset($this->addressFormatRepository)) {
      $this->addressFormatRepository = \Drupal::service('address.address_format_repository');
    }
    return $this->addressFormatRepository;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\save_event_location\FormAlter;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\indiecommerce_core\ConfigHelperTrait;
use Drupal\recurring_events\Entity\EventSeries;

/**
 * Event instance form customization.
 */
class EventInstanceFormAlter {

  use ConfigHelperTrait;
  use EventFormLayoutTrait;
  use StringTranslationTrait;
  use DependencySerializationTrait;
  use EventSaveLocationTrait;

  /**
   * Event instance form modifications.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @see save_event_location_form_eventinstance_form_alter()
   */
  public function alter(array &$form, FormStateInterface $form_state): void {
    $this->eventFormLayout($form);

    // Add select list with previously saved addresses.
    if ($this->savedEventLocationField()) {
      $form['group_location']['saved_event_locations'] = $this->savedEventLocationField();
      $form['group_location']['saved_event_locations']['#weight'] = $form['field_events_custom_other_loc']['#weight'] - .1;
      $form['group_location']['saved_event_locations']['#states'] = [
        'visible' => [
          'select[name="field_events_custom_location"]' => ['value' => 'other'],
        ],
      ];
      $form['group_location']['saved_event_locations']['#ajax']['wrapper'] = 'edit-field-other-location-0-address';
    }

    // Add checkbox to save the event address for future use.
    $form['group_location']['save_location_address'] = $this->rememberAddressField();
    $form['group_location']['save_location_address']['#weight'] = $form['field_events_custom_other_loc']['#weight'] + 1;
    $form['group_location']['save_location_address']['#states'] = [
      'visible' => [
        'select[name="field_events_custom_location"]' => ['value' => 'other'],
      ],
    ];

    // Show/Hide Location Related fields based on field_location.
    $form['field_select_an_address']['widget']['#states']['visible']['select[name="field_events_custom_location"]'] = ['value' => 'other'];

    // Add custom callbacks to control submissions.
    $form['#validate'][] = [$this, 'validateLocationAddress'];

    // Save the event location if needed.
    $form['actions']['submit']['#submit'][] = [$this, 'saveEventLocation'];
  }

}

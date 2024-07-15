<?php

namespace Drupal\entrust_salesforce\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Provides a password management form.
 */
class EntrustSalesforceSettingsForm extends FormBase
{
  protected $database;
  protected $messenger;

  public function __construct(Connection $database, MessengerInterface $messenger)
  {
    $this->database = $database;
    $this->messenger = $messenger;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('database'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'entrust_salesforce_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Enter a new password.'),
      '#suffix' => '<div><b><i>Default Value is Firmware</i></b></div>',
      '#maxlength' => 100,
    ];

    $selected_mode = \Drupal::database()->query('SELECT Mode FROM {entrust_salesforce_save_password} WHERE id = :id', [':id' => 1])->fetchField();
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Select Mode'),
      '#options' => [
        'development' => $this->t('Development Mode'),
        'production' => $this->t('Production Mode'),
      ],
      '#default_value' => $selected_mode,
      '#suffix' => "<div><b><i> Default mode is Development</i><b> </div>"
    ];

    $form['password_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Setting'),
    ];


    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    // Get the submitted password.
    $submitted_password = $form_state->getValue('password');

    $selected_mode = $form_state->getValue('mode');

    $row_exists = $this->database->query("SELECT identifier FROM {entrust_salesforce_save_password} WHERE identifier = :id", [':id' => 1])->fetchField();

    // Initialize data with identifier.
    $data = ['identifier' => 1];
    // Add password to data if provided.
    if (!empty($submitted_password)) {
      $data['Password'] = base64_encode($submitted_password);
    }

    // Add mode to data if provided.
    if (!empty($selected_mode)) {
      $data['Mode'] = $selected_mode;
    }

    if (!empty($submitted_password)  || !empty($selected_mode)) {
      if ($row_exists) {
        // Update the existing row.
        $this->database->update('entrust_salesforce_save_password')
          ->fields($data)
          ->condition('identifier', 1)
          ->execute();
      } else {
        // Insert a new row.
        if (empty($submitted_password)) {
          $data['Password'] = base64_encode("firmware");
        }

        $this->database->insert('entrust_salesforce_save_password')
          ->fields($data)
          ->execute();
      }
    }


    // Display a status message.
    $this->messenger->addStatus($this->t('Setting saved successfully.'));

    // Redirect to the same page.
    $form_state->setRedirect('entrust_salesforce.settings_form');
  }
}

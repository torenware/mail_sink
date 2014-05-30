<?php

/**
 * @file
 *   Form controller for Mail Sink settings
 */


namespace Drupal\mail_sink\Form;

use Drupal\Core\Form\ConfigFormBase;

/**
 * Configure logging settings for this site.
 */
class MailSinkConfigForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mail_sink_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state) {
    $config = $this->config('mail_sink.settings');
    $interface = $this->get_mail_config();
    $form['enabled'] = array(
      '#type' => 'checkbox',
      '#title' => t('Mail Sink is active'),
      '#default_value' => $this->is_sink_active(),
      '#description' => t('When this is enabled, outgoing mail will be simulated, and the text of outgoing mails will be sent to a file.'),
    );
    $form['file_path'] = array(
      '#type' => 'textfield',
      '#title' => t('Path to Log File'),
      '#description' => t('A URI or file path where the log file will be stored.'),
      '#default_value' => $config->get('file_path'),
      '#required' => TRUE,
    );

    return parent::buildForm($form, $form_state);
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, array &$form_state) {
    if ($form_state['values']['enabled']) {
      $path = $form_state['values']['file_path'];
      $dir_name = drupal_dirname($path);
      $worked = file_prepare_directory($dir_name, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);
      if (!$worked) {
        $this->setFormError('file_path', $form_state,
                            $this->t('We could not create the directory "@path"',
                                     array('@path' => $dir_name))
                            );
      }
      else {
        $exists = FALSE;
        if (!file_exists($path)) {
          $hndl = @fopen($path, "w");
          if ($hndl) {
            fclose($hndl);
            $exists = TRUE;
          }
        }
        else {
          $exists = TRUE;
        }
        if (!$exists) {
          $this->setFormError('file_path', $form_state,
                              $this->t('Could not create log file @log',
                                       array('@log' => $path)));
        }
        else if (!is_writable($path)) {
          $this->setFormError('file_path', $form_state,
                              $this->t('The log file @log is not writable by the web server',
                                       array('@log' => $path)));          
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, array &$form_state) {
    $this->set_mail_sink($form_state['values']['enabled']);
    $this->config('mail_sink.settings')
      ->set('file_path', $form_state['values']['file_path'])
      ->save();

    parent::submitForm($form, $form_state);
  }
  
  /**
   * Get the current mail settings
   */
  protected function get_mail_config() {
    $interface = $this->config('system.mail')->get('interface');
    return $interface;
  }
  
  /**
   * Is the mail sink active?
   */
  protected function is_sink_active() {
    $interface = $this->get_mail_config();
    //if default mail plug in is our plugin, we are active
    if (!empty($interface['default']) and $interface['default'] == 'mail_sink_logger') {
      return TRUE;
    }
    return FALSE;
  }
  
  /**
   * Turn on or off the mail sink
   * @param boolean $state
   */
  protected function set_mail_sink($state) {
    $current_state = $this->is_sink_active();
    if (!$current_state and !$state or
        $current_state and $state) {
      return;
    }
    //we need to toggle state
    $mail_config = $this->config('system.mail');
    $sink_config = $this->config('mail_sink.settings');
    $interface = $mail_config->get('interface');
    if ($state) {
      //to turn the sink on, set it as our default mail plugin
      $default = $interface['default'];
      $sink_config->set('overriding_mailer_id', $default);
      $interface['default'] = 'mail_sink_logger';
    }
    else {
      $new_default = $sink_config->get('overriding_mailer_id');
      $new_default = $new_default ? $new_default : 'php_mail';
      $sink_config->set('overriding_mailer_id', FALSE);
      $interface['default'] = $new_default;
    }
    $mail_config->set('interface', $interface);
    $mail_config->save();
    $sink_config->save();
  }

}

<?php

/**
 * @file
 * Contains \Drupal\mail_sink\Plugin\Mail\MailSinkLog.
 */

namespace Drupal\mail_sink\Plugin\Mail;

use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Mail\Plugin\Mail\PhpMail;
use Drupal\Core\Site\Settings;


/**
 * Defines a mail backend that captures sent messages and writes them to disk.
 *
 * This class is for running tests or for development.
 *
 * @Mail(
 *   id = "mail_sink_logger",
 *   label = @Translation("Mail Sink mail logger"),
 *   description = @Translation("Does not send the message, but appends it to a mail log in a configureable location.")
 * )
 */
class MailSinkLog extends PhpMail implements MailInterface {

  /**
   * {@inheritdoc}
   */
  public function mail(array $message) {
    $subject = $message['subject'];
    $mail_args = $this->preprocess_mail($message);
    $to = $mail_args['to'];
    
    $output = "\n=====================\n";
    $output .= $mail_args['headers'] . "\n";
    $output .= "Subject: " . $mail_args['subject'] . "\n";
    $output .= "To: " . $mail_args['to'] . "\n\n";
    $output .= $mail_args['body'];
    $this->write_to_log($output);
    drupal_set_message("An email message to '$to' was simulated", 'status');
    watchdog('MAIL SINK', "Test mail '@subject' would be sent",
             array('@subject' => $message['subject']),
             WATCHDOG_NOTICE);
    return TRUE;
  }
  
  /**
   * Turn a message array into a mail() ready set of items.
   *
   * @param array $message
   *   A message array, as described in hook_mail_alter().
   *
   * @return array
   *   array of the params passed as arguments to mail().
   *
   * @see http://php.net/manual/en/function.mail.php
   * @see drupal_mail()
   */
  protected function preprocess_mail(array $message) {
    // If 'Return-Path' isn't already set in php.ini, we pass it separately
    // as an additional parameter instead of in the header.
    if (isset($message['headers']['Return-Path'])) {
      $return_path_set = strpos(ini_get('sendmail_path'), ' -f');
      if (!$return_path_set) {
        $message['Return-Path'] = $message['headers']['Return-Path'];
        unset($message['headers']['Return-Path']);
      }
    }
    //add the date
    $message['headers']['Date'] = date('r');
    $mimeheaders = array();
    foreach ($message['headers'] as $name => $value) {
      $mimeheaders[] = $name . ': ' . mime_header_encode($value);
    }
    $line_endings = Settings::get('mail_line_endings', PHP_EOL);
    // Prepare mail commands.
    $mail_subject = mime_header_encode($message['subject']);
    // Note: e-mail uses CRLF for line-endings. PHP's API requires LF
    // on Unix and CRLF on Windows. Drupal automatically guesses the
    // line-ending format appropriate for your system. If you need to
    // override this, adjust $settings['mail_line_endings'] in settings.php.
    $mail_body = preg_replace('@\r?\n@', $line_endings, $message['body']);
    // For headers, PHP's API suggests that we use CRLF normally,
    // but some MTAs incorrectly replace LF with CRLF. See #234403.
    $mail_headers = join("\n", $mimeheaders);

    // We suppress warnings and notices from mail() because of issues on some
    // hosts. The return value of this method will still indicate whether mail
    // was sent successfully.

    // On most non-Windows systems, the "-f" option to the sendmail command
    // is used to set the Return-Path. There is no space between -f and
    // the value of the return path.
    $additional_headers = isset($message['Return-Path']) ? '-f' . $message['Return-Path'] : '';
    $mail_params = array(
      'to' => $message['to'],
      'subject' => $mail_subject,
      'body' => $mail_body,
      'headers' => $mail_headers,
      'additional_headers' => $additional_headers
    );

    return $mail_params;
  }
  
  /**
   * Actually write to the log file
   */
  protected function write_to_log($buffer) {
    $hndl = $this->get_log();
    if (!$hndl) return;
    fwrite($hndl, $buffer);
    fclose($hndl);
  }
  
  /**
   * Get an append handle to the log
   */
  protected function get_log() {
    $config = \Drupal::config('mail_sink.settings');
    $path = $config->get('file_path');
    if (!file_exists($path)) {
      $dir = drupal_dirname($path);
      if (!file_prepare_directory($dir, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS)) {
        return FALSE;
      }
    }
    $hndl = @fopen($path, 'a');
    return $hndl;
  }
  
}

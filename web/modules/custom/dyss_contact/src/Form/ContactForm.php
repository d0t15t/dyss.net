<?php

namespace Drupal\dyss_contact\Form;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The /contact form: name, email, subject and message.
 *
 * Messages go to the address in dyss_contact.settings, from the site's own
 * address with the visitor as Reply-To, so mail servers don't treat them as
 * spoofed and a reply reaches the visitor.
 */
final class ContactForm extends FormBase {

  /**
   * Messages allowed per visitor (IP address) and window.
   */
  const FLOOD_LIMIT = 5;

  /**
   * The flood window in seconds.
   */
  const FLOOD_WINDOW = 3600;

  /**
   * The longest message accepted, in characters.
   */
  const MAX_MESSAGE_LENGTH = 5000;

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly FloodInterface $flood,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('flood'),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dyss_contact_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#required' => TRUE,
      '#maxlength' => 100,
      '#attributes' => ['autocomplete' => 'name'],
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'email'],
    ];
    $form['subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#required' => TRUE,
      '#maxlength' => 200,
    ];
    $form['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Message'),
      '#required' => TRUE,
      '#rows' => 8,
    ];
    // Honeypot: hidden from people, filled in by many spam bots.
    $form['website'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Leave this field empty'),
      '#wrapper_attributes' => ['class' => ['visually-hidden']],
      '#attributes' => ['autocomplete' => 'off', 'tabindex' => '-1'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send message'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (mb_strlen($form_state->getValue('message')) > self::MAX_MESSAGE_LENGTH) {
      $form_state->setErrorByName('message', $this->t('Please keep your message under @count characters.', ['@count' => self::MAX_MESSAGE_LENGTH]));
    }
    if (!$this->flood->isAllowed('dyss_contact', self::FLOOD_LIMIT, self::FLOOD_WINDOW)) {
      $form_state->setErrorByName('', $this->t('You have sent several messages in a short time. Please try again later.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('dyss_contact.form');
    $this->flood->register('dyss_contact', self::FLOOD_WINDOW);

    // A bot filled in the honeypot: pretend it worked, send nothing.
    if ($form_state->getValue('website') !== '') {
      $this->messenger()->addStatus($this->t('Thank you, your message has been sent.'));
      return;
    }

    $params = [
      'name' => trim($form_state->getValue('name')),
      'email' => $form_state->getValue('email'),
      'subject' => trim($form_state->getValue('subject')),
      'message' => $form_state->getValue('message'),
    ];
    $recipient = $this->config('dyss_contact.settings')->get('recipient');
    $langcode = $this->languageManager->getDefaultLanguage()->getId();
    $result = $this->mailManager->mail('dyss_contact', 'contact', $recipient, $langcode, $params);

    if (!empty($result['result'])) {
      $this->logger('dyss_contact')->notice('Contact message from %name (%email).', [
        '%name' => $params['name'],
        '%email' => $params['email'],
      ]);
      $this->messenger()->addStatus($this->t('Thank you, your message has been sent.'));
    }
    else {
      $this->messenger()->addError($this->t('Sorry, your message could not be sent. Please try again later.'));
      $form_state->setRebuild();
    }
  }

}

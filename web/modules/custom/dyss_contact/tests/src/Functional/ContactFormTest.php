<?php

namespace Drupal\Tests\dyss_contact\Functional;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\dyss_contact\Form\ContactForm;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the /contact form and its settings.
 */
#[Group('dyss_contact')]
#[RunTestsInSeparateProcesses]
class ContactFormTest extends BrowserTestBase {

  use AssertMailTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['dyss_contact'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Fills in and submits the contact form.
   */
  protected function submitContactForm(array $overrides = []): void {
    $this->drupalGet('/contact');
    $this->submitForm($overrides + [
      'name' => 'Ada Lovelace',
      'email' => 'ada@example.com',
      'subject' => 'A question',
      'message' => "Hello,\nis the mix from last week online? <3 & thanks",
    ], 'Send message');
  }

  /**
   * Anonymous visitors can send a message to the configured address.
   */
  public function testMessageIsMailed(): void {
    $this->submitContactForm();
    $this->assertSession()->titleEquals('Contact: mr@dyss.net | Drupal');
    $this->assertSession()->elementTextEquals('css', 'h1 .dyss-contact-email', 'mr@dyss.netCopy email address');
    $this->assertSession()->elementExists('css', 'h1 button.dyss-contact-email__copy');
    $this->assertSession()->pageTextContains('Thank you, your message has been sent.');

    $mails = $this->getMails(['id' => 'dyss_contact_contact']);
    $this->assertCount(1, $mails);
    $mail = reset($mails);
    $this->assertSame('mr@dyss.net', $mail['to']);
    $this->assertStringEndsWith('] A question', $mail['subject']);
    $this->assertSame('ada@example.com', $mail['headers']['Reply-To']);
    $this->assertStringNotContainsString('ada@example.com', $mail['from'], 'Mail is sent from the site, not the visitor.');
    $this->assertStringContainsString('Ada Lovelace <ada@example.com>', $mail['body']);
    $this->assertStringContainsString('is the mix from last week online? <3 & thanks', $mail['body']);
  }

  /**
   * The recipient comes from the settings form.
   */
  public function testRecipientSetting(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet('/admin/config/system/dyss-contact');
    $this->assertSession()->fieldValueEquals('recipient', 'mr@dyss.net');
    $this->submitForm(['recipient' => 'not an address'], 'Save configuration');
    $this->assertSession()->pageTextContains('is not valid');
    $this->submitForm(['recipient' => 'other@example.com'], 'Save configuration');
    $this->assertSame('other@example.com', $this->config('dyss_contact.settings')->get('recipient'));
    $this->drupalLogout();

    $this->submitContactForm();
    $this->assertSession()->titleEquals('Contact: other@example.com | Drupal');
    $mails = $this->getMails(['id' => 'dyss_contact_contact']);
    $this->assertSame('other@example.com', end($mails)['to']);

    $this->drupalGet('/admin/config/system/dyss-contact');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Bots filling in the honeypot get a success message but send nothing.
   */
  public function testHoneypot(): void {
    $this->submitContactForm(['website' => 'https://spam.example.com']);
    $this->assertSession()->pageTextContains('Thank you, your message has been sent.');
    $this->assertCount(0, $this->getMails(['id' => 'dyss_contact_contact']));
  }

  /**
   * Visitors are limited to a few messages per hour.
   */
  public function testFloodControl(): void {
    for ($i = 0; $i < ContactForm::FLOOD_LIMIT; $i++) {
      $this->submitContactForm();
    }
    $this->submitContactForm();
    $this->assertSession()->pageTextContains('You have sent several messages in a short time.');
    $this->assertCount(ContactForm::FLOOD_LIMIT, $this->getMails(['id' => 'dyss_contact_contact']));
  }

}

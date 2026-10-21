<?php

namespace Drupal\Tests\persistent_player\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests moving the navigation settings to htmx_navigation.
 */
#[Group('persistent_player')]
#[RunTestsInSeparateProcesses]
class NavigationUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'block', 'htmx_navigation', 'persistent_player'];

  /**
   * The former settings are carried over and removed.
   */
  public function testUpdate(): void {
    $this->installConfig(['htmx_navigation']);
    // As stored by the former module version, whose schema is gone.
    $this->container->get('config.storage')->write('persistent_player.settings', [
      'content_selector' => '#main-swap',
      'enabled_pjax_nav' => FALSE,
      'excluded_path_patterns' => ['/admin*', '/shop/*'],
    ]);

    \Drupal::moduleHandler()->loadInclude('persistent_player', 'install');
    persistent_player_update_11001();

    $settings = $this->config('htmx_navigation.settings');
    $this->assertSame('#main-swap', $settings->get('content_selector'));
    $this->assertFalse($settings->get('enabled'));
    $this->assertSame(['/admin*', '/shop/*'], $settings->get('excluded_path_patterns'));
    $this->assertTrue($this->config('persistent_player.settings')->isNew(), 'The former settings are removed.');
  }

  /**
   * Sites without the former settings keep the defaults.
   */
  public function testUpdateWithoutFormerSettings(): void {
    $this->installConfig(['htmx_navigation']);
    \Drupal::moduleHandler()->loadInclude('persistent_player', 'install');
    persistent_player_update_11001();
    $settings = $this->config('htmx_navigation.settings');
    $this->assertTrue($settings->get('enabled'));
    $this->assertSame('#pjax-content', $settings->get('content_selector'));
  }

}

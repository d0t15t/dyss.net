<?php

namespace Drupal\dj_mixes_mixcloud\Drush\Commands;

use Drupal\dj_mixes_mixcloud\MixcloudImporter;
use Drupal\media\MediaInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Mixcloud media.
 */
final class MixcloudCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly MixcloudImporter $importer,
  ) {
    parent::__construct();
  }

  /**
   * Imports or refreshes an account's Mixcloud uploads and links them to mixes.
   */
  #[CLI\Command(name: 'dj-mixes:mixcloud-import', aliases: ['mixcloud-import'])]
  #[CLI\Argument(name: 'username', description: 'The Mixcloud account, e.g. BCR_Radio.')]
  #[CLI\Option(name: 'filter', description: 'Only uploads whose name or tags contain this text.')]
  #[CLI\Option(name: 'no-link', description: 'Import the media without linking them to DJ mixes.')]
  #[CLI\Usage(
    name: 'drush dj-mixes:mixcloud-import BCR_Radio --filter=dyssem',
    description: 'Import the Dyssembler shows from Berlin Community Radio.',
  )]
  public function import(
    string $username,
    array $options = ['filter' => NULL, 'no-link' => FALSE],
  ): void {
    $media = $this->importer->import($username, $options['filter'], function (MediaInterface $item) {
      $this->logger()->info('{name}', ['name' => $item->getName()]);
    });
    $this->logger()->success(dt('@count uploads of @account imported.', [
      '@count' => count($media),
      '@account' => $username,
    ]));
    if ($options['no-link']) {
      return;
    }
    $linked = $this->importer->linkToMixes($media);
    $this->logger()->success(dt('@count DJ mixes linked to Mixcloud.', ['@count' => count($linked)]));
  }

}

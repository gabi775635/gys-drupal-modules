<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Block\BlockManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller pour la vue simplifiée.
 */
class SimpleNavController extends ControllerBase {

  /**
   * Le gestionnaire de block.
   *
   * @var \Drupal\Core\Block\BlockManagerInterface
   */
  protected $blockManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(BlockManagerInterface $block_manager) {
    $this->blockManager = $block_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.block')
    );
  }


  /**
 * Titre dynamique basé sur id_char_version.
 */
public function title(string $id_char_version): string {
  $name = str_replace('_', ' ', $id_char_version);
  return ucfirst($name);
}


  /**
   * Page qui affiche le block simple view.
   */
  public function content($id_char_version) {
    $block = $this->blockManager->createInstance('gestion_documentaire_simple_nav_block', [
      'id_char_version' => $id_char_version,
    ]);
    $render = $block->build();

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['gestion-documentaire-simple-nav']],
      'content' => $render,
      '#attached' => $render['#attached'] ?? [],
    ];
  }

}
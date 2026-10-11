<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Block\BlockManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Database\Database;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\Client;
use Drupal\Core\Url;


/**
* Controller pour la gestion documentaire.
*/
class HomePageController extends ControllerBase {
  
  /**
  * Le gestionnaire de block.
  *
  * @var \Drupal\Core\Block\BlockManagerInterface
  */
  protected $blockManager;
  
  /**
  * Injecte le service block.manager.
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
  * Page qui affiche le block gestionDocumentaireAction.
  */
  public function content($id_char_version) {
    $block = $this->blockManager->createInstance('gestion_documentaire_home_block', [
      'id_char_version' => $id_char_version,
    ]);
    $render = $block->build();
  
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['gestion_documentaire-page']],
      'content' => $render,
      '#attached' => $render['#attached'] ?? [],
    ];
  }

}
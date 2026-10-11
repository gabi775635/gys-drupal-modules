<?php

namespace Drupal\gestion_documentaire\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Database;

/**
 * Fournit un bloc "Modifications récentes".
 *
 * @Block(
 *   id = "gestion_documentaire_dashboard_block",
 *   admin_label = @Translation("Modifications récentes (gestion documentaire)")
 * )
 */
class DashboardBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $connection = Database::getConnection('drupal9');
    $parent = \Drupal::routeMatch()->getParameter('parent') ?? NULL;


    // 🔹 Ou sinon tu fais une requête directe ici
    // $rows = $connection->query("SELECT ...")->fetchAll();

    return [
      '#theme' => 'dashboard_template',
      '#parent' => $parent,
      '#cache' => ['max-age' => 0],
    ];
  }
}

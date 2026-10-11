<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Utility\Xss;

class RedirectMenuController extends ControllerBase
{

  public function hierarchyList(AccountInterface $user = NULL)
  {
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $current_user = $user ?? $this->currentUser();
    $user_roles = $current_user->getRoles();

    // --- RÈGLE PRIORITAIRE : admin_filiale_* ---
    $admin_filiale_roles = preg_grep('/^admin_filiale_/', $user_roles);

    if (!empty($admin_filiale_roles)) {
      // On prend le premier rôle admin_filiale_* trouvé
      $filiale_role = reset($admin_filiale_roles);

      // Connexion à la base pour chercher le nom de la taxonomy
      try {
        Database::setActiveConnection('drupal9');
        $connection = Database::getConnection();

        // On cherche dans gestion_documentaire_role le role_libelle = $filiale_role
        // puis on récupère le nom de la taxonomy associée
        $query = $connection->select('gestion_documentaire_role', 'gdr');
        $query->addField('gdr', 'id_char');
        $query->condition('gdr.role_libelle', $filiale_role);
        $query->condition('gdr.can_view', 1);
        $id_char = $query->execute()->fetchField();

        if ($id_char && strpos($id_char, 'taxonomy_') === 0) {
          $tid = substr($id_char, strlen('taxonomy_'));

          // Récupérer le nom de la taxonomy depuis taxonomy_term_field_data
          $name = $connection->select('taxonomy_term_field_data', 't')
            ->fields('t', ['name'])
            ->condition('t.tid', $tid)
            ->execute()
            ->fetchField();

          if ($name) {
            return new RedirectResponse(
              Url::fromRoute('gestion_documentaire.dossier.view', ['nom' => $name], ['language' => \Drupal::languageManager()->getCurrentLanguage()])->toString()
            );
          }
        }
      } catch (\Exception $e) {
        \Drupal::logger('gestion_documentaire')->error('Erreur recherche filiale: @message', ['@message' => $e->getMessage()]);
      }
    }

    // --- Fin règle prioritaire ---

    if (in_array('administrator', $user_roles) || in_array('admin_gestiondocumentaire', $user_roles)) {
      return new RedirectResponse(Url::fromRoute('gestion_documentaire.dossier.view', ['nom' => 'racine'], ['language' => \Drupal::languageManager()->getCurrentLanguage()])->toString());
    }

    if (in_array('adminmktproduit', $user_roles)) {
      return new RedirectResponse(Url::fromRoute('gestion_documentaire.dossier.view', ['nom' => 'marketing'], ['language' => \Drupal::languageManager()->getCurrentLanguage()])->toString());
    }

    try {
      Database::setActiveConnection('drupal9');
      $connection = Database::getConnection();
      // \Drupal::logger('gestion_documentaire')->notice('Database connection successful');
    } catch (\Exception $e) {
      \Drupal::logger('gestion_documentaire')->error('Database connection failed: @message', ['@message' => $e->getMessage()]);
      return ['#markup' => $this->t('Erreur de connexion à la base de données')];
    }

    $standard_roles = ['anonymous', 'authenticated'];
    $custom_roles = array_diff($user_roles, $standard_roles);
    $has_only_standard_roles = empty($custom_roles);

    // \Drupal::logger('gestion_documentaire')->notice('Custom roles: @custom', ['@custom' => implode(', ', $custom_roles)]);

    $first_standard_role_match_name = NULL;

    $query = $connection->select('taxonomy_term_field_data', 't');
    $query->fields('t', ['tid', 'name']);
    $query->leftJoin('gestion_documentaire_role', 'gdr', "CONCAT('taxonomy_', t.tid) = gdr.id_char AND gdr.can_view = 1");
    $query->addField('gdr', 'role_libelle', 'required_role');
    $query->orderBy('t.tid', 'ASC');

    $results = $query->execute()->fetchAll();

    $taxonomies = [];
    foreach ($results as $row) {
      $tid  = $row->tid;
      $name = $row->name;
      if (!isset($taxonomies[$tid])) {
        $taxonomies[$tid] = ['tid' => $tid, 'name' => $name, 'roles' => []];
      }
      if ($row->required_role) {
        $taxonomies[$tid]['roles'][] = $row->required_role;
      }
    }

    $language = \Drupal::languageManager()->getCurrentLanguage();

    // PASSE 1 : rôles personnalisés
    if (!$has_only_standard_roles) {
      foreach ($taxonomies as $taxonomy) {
        $name           = $taxonomy['name'];
        $required_roles = $taxonomy['roles'];

        if (empty($required_roles)) continue;

        foreach ($required_roles as $required_role) {
          if (in_array($required_role, $standard_roles)) {
            if ($first_standard_role_match_name === NULL && in_array($required_role, $user_roles)) {
              $first_standard_role_match_name = $name;
            }
            continue;
          }

          if (in_array($required_role, $custom_roles)) {
            return new RedirectResponse(
              Url::fromRoute('gestion_documentaire.dossier.view', ['nom' => $name], ['language' => $language])->toString()
            );
          }
        }
      }
    }

    // PASSE 2 : fallback rôle standard
    if ($first_standard_role_match_name !== NULL) {
      return new RedirectResponse(
        Url::fromRoute('gestion_documentaire.dossier.view', ['nom' => $first_standard_role_match_name], ['language' => $language])->toString()
      );
    }

    // PASSE 3 : rôles standards
    foreach ($taxonomies as $taxonomy) {
      $name           = $taxonomy['name'];
      $required_roles = $taxonomy['roles'];

      foreach ($required_roles as $required_role) {
        if (in_array($required_role, $standard_roles) && in_array($required_role, $user_roles)) {
          return new RedirectResponse(
            Url::fromRoute('gestion_documentaire.dossier.view', ['nom' => $name], ['language' => $language])->toString()
          );
        }
      }
    }

    return [
      '#markup' => Xss::filterAdmin('<p>' . $this->t('Aucune taxonomy accessible pour vos rôles.') . '</p>'),
    ];
  }
}
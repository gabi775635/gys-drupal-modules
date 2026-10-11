<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contrôleur unifié pour la gestion des ressources (dossiers et documents).
 */
class RessourceController extends ControllerBase
{

  protected $httpKernel;

  public function __construct(HttpKernelInterface $http_kernel)
  {
    $this->httpKernel = $http_kernel;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('http_kernel')
    );
  }

  /**
   * Crée une sous-requête en propageant correctement la méthode HTTP et les données POST.
   */
  protected function createSubRequest(string $path)
  {
    $current_request = \Drupal::request();
    $method          = $current_request->getMethod();

    $host      = $current_request->getHttpHost();
    $base_path = $current_request->getBasePath();
    $language  = \Drupal::languageManager()->getCurrentLanguage()->getId();

    $full_url = 'https://' . $host . $base_path . '/' . $language . $path;

    $server                   = $current_request->server->all();
    $server['REQUEST_URI']    = '/' . $language . $path;
    $server['HTTPS']          = 'on';
    $server['SERVER_PORT']    = 443;
    $server['REQUEST_SCHEME'] = 'https';

    $request = Request::create(
      $full_url,
      $method,
      $method === 'POST' ? $current_request->request->all() : [],
      $current_request->cookies->all(),
      $current_request->files->all(),
      $server
    );

    if ($current_request->hasSession()) {
      $request->setSession($current_request->getSession());
    }

    return $this->httpKernel->handle($request, HttpKernelInterface::SUB_REQUEST);
  }

  /**
   * Construit l'URL publique lisible pour un nom et un type de ressource.
   */
  protected function buildPublicUrl(string $nom, string $resource_type): string
  {
    $current_request = \Drupal::request();
    $host            = $current_request->getHttpHost();
    $base_path       = $current_request->getBasePath();
    $language        = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $segment         = $resource_type === 'taxonomy' ? 'dossier' : 'document';

    return 'https://' . $host . $base_path . '/' . $language
      . '/gestion_documentaire/' . $segment . '/' . rawurlencode($nom);
  }

  /**
   * Recherche une entité par nom dans toutes les langues.
   * Pour les médias : entityQuery simple.
   * Pour les taxonomies : PASS1 (langue courante native) puis PASS2 (autres langues natives).
   */
  protected function findEntityByName(string $nom, string $entity_type_id): ?array
  {
    // =========================
    // CAS MEDIA (simple)
    // =========================
    if ($entity_type_id === 'media') {
      $query = \Drupal::entityQuery('media')
        ->condition('name', $nom)
        ->range(0, 1)
        ->accessCheck(FALSE);

      $ids = $query->execute();

      if (empty($ids)) {
        return NULL;
      }

      $entity = \Drupal::entityTypeManager()
        ->getStorage('media')
        ->load(reset($ids));

      return [
        'entity'               => $entity,
        'matched_langcode'     => \Drupal::languageManager()->getCurrentLanguage()->getId(),
        'matched_name'         => $nom,
        'name_in_current_lang' => NULL,
      ];
    }

    // =========================
    // CAS TAXONOMY (multilingue)
    // =========================
    $current_langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $languages        = \Drupal::languageManager()->getLanguages();
    $storage          = \Drupal::entityTypeManager()->getStorage($entity_type_id);
    $all_entities     = $storage->loadMultiple();

    // --- PASS 1 : langue courante, traduction NATIVE uniquement ---
    foreach ($all_entities as $entity) {
      if (!$entity->hasTranslation($current_langcode)) {
        continue;
      }

      $translated = $entity->getTranslation($current_langcode);

      // Vérifier que c'est bien une vraie trad native, pas un fallback silencieux de Drupal
      if ($translated->language()->getId() !== $current_langcode) {
        continue;
      }

      $label = trim((string) $translated->getName());

      if ($label === trim($nom)) {
        return [
          'entity'               => $entity,
          'matched_langcode'     => $current_langcode,
          'matched_name'         => $nom,
          'name_in_current_lang' => NULL,
        ];
      }
    }

    // --- PASS 2 : toutes les autres langues, traduction NATIVE uniquement ---
    foreach ($all_entities as $entity) {
      foreach ($languages as $langcode => $language) {
        if ($langcode === $current_langcode) {
          continue;
        }
        if (!$entity->hasTranslation($langcode)) {
          continue;
        }

        $translated = $entity->getTranslation($langcode);

        // Vérifier que c'est bien une vraie trad native
        if ($translated->language()->getId() !== $langcode) {
          continue;
        }

        $label = trim((string) $translated->getName());

        if ($label === trim($nom)) {
          // Récupérer le nom dans la langue courante — natif uniquement
          $name_in_current_lang = NULL;
          if ($entity->hasTranslation($current_langcode)) {
            $trad_courante = $entity->getTranslation($current_langcode);
            if ($trad_courante->language()->getId() === $current_langcode) {
              $name_in_current_lang = trim((string) $trad_courante->getName());
            }
          }

          // $this->getLogger('gestion_documentaire')->notice(
          //   '[findEntityByName] ✅ PASS2 match natif | TID=@id | lang=@lang_found | nom_lang_courante="@nom_current"',
          //   [
          //     '@id'          => $entity->id(),
          //     '@lang_found'  => $langcode,
          //     '@nom_current' => $name_in_current_lang ?? '(pas de trad native courante)',
          //   ]
          // );

          return [
            'entity'               => $entity,
            'matched_langcode'     => $langcode,
            'matched_name'         => $nom,
            'name_in_current_lang' => $name_in_current_lang,
          ];
        }
      }
    }

    return NULL;
  }

  /**
   * Résout un nom de ressource vers son ID.
   * Pour les taxonomies uniquement : RedirectResponse 302 si nom dans mauvaise langue.
   * Pour les médias : sous-requête directe toujours.
   */
  protected function resolveRessource(string $nom, string $resource_type, string $path_pattern)
  {
    $entity_type_id   = $resource_type === 'taxonomy' ? 'taxonomy_term' : 'media';
    $id_prefix        = $resource_type === 'taxonomy' ? 'taxonomy_'     : 'media_';
    $current_langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();

    $result = $this->findEntityByName($nom, $entity_type_id);

    if (!$result) {
      throw new NotFoundHttpException(sprintf(
        '%s non trouvé : %s',
        $resource_type === 'taxonomy' ? 'Dossier' : 'Document',
        $nom
      ));
    }

    // TAXONOMY uniquement : redirect si nom dans mauvaise langue
    if ($resource_type === 'taxonomy' && $result['matched_langcode'] !== $current_langcode) {
      
      // Si une traduction native existe dans la langue courante → redirect
      if (!empty($result['name_in_current_lang'])) {
        $nom_correct  = $result['name_in_current_lang'];
        $redirect_url = $this->buildPublicUrl($nom_correct, $resource_type);

        // $this->getLogger('gestion_documentaire')->notice(
        //   '[resolveRessource] 🔄 REDIRECT: "@nom_original" (@lang_found) → "@nom_correct" (@lang_current) | url=@url',
        //   [
        //     '@nom_original' => $nom,
        //     '@lang_found'   => $result['matched_langcode'],
        //     '@nom_correct'  => $nom_correct,
        //     '@lang_current' => $current_langcode,
        //     '@url'          => $redirect_url,
        //   ]
        // );

        return new RedirectResponse($redirect_url, 302);
      }
      
      // Pas de traduction native dans la langue courante → on garde le nom fourni
      // $this->getLogger('gestion_documentaire')->notice(
      //   '[resolveRessource] ℹ️ Pas de trad en @lang_current pour "@nom", conservation du nom en @lang_found',
      //   [
      //     '@nom'          => $nom,
      //     '@lang_current' => $current_langcode,
      //     '@lang_found'   => $result['matched_langcode'],
      //   ]
      // );
      // On laisse passer → la sous-requête directe utilisera l'ID
    }

    // Sous-requête directe (média, ou taxo déjà dans la bonne langue, ou pas de redirect)
    $id              = $result['entity']->id();
    $id_char_version = $id_prefix . $id;

    // $this->getLogger('gestion_documentaire')->notice(
    //   '[resolveRessource] ➡️ Sous-requête directe | ID=@id | path=@pattern',
    //   [
    //     '@id'      => $id_char_version,
    //     '@pattern' => $path_pattern,
    //   ]
    // );

    $path = sprintf($path_pattern, $id_char_version);
    return $this->createSubRequest($path);
  }

  /**
   * Affiche une ressource (dossier ou document).
   */
  public function view($nom, $resource_type)
  {
    if ($resource_type === 'taxonomy') {
      return $this->resolveRessource($nom, $resource_type, '/gestion_documentaire/taxonomy/%s');
    }
    return $this->resolveRessource($nom, $resource_type, '/document/%s');
  }

  /**
   * Télécharge un document.
   */
  public function download($nom, $resource_type)
  {
    return $this->resolveRessource($nom, $resource_type, '/document/%s/download');
  }

  /**
   * Résout un nom et exécute une sous-requête vers l'action demandée.
   * Pas de redirect ici, on résout directement via l'ID.
   */
  public function resolveAndRedirect($nom, $resource_type, $action)
  {
    $entity_type_id   = $resource_type === 'taxonomy' ? 'taxonomy_term' : 'media';
    $id_prefix        = $resource_type === 'taxonomy' ? 'taxonomy_'     : 'media_';
    $current_langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();

    $result = $this->findEntityByName($nom, $entity_type_id);

    if (!$result) {
      throw new NotFoundHttpException(sprintf(
        '%s non trouvé : %s',
        $resource_type === 'taxonomy' ? 'Dossier' : 'Document',
        $nom
      ));
    }

    $id              = $result['entity']->id();
    $id_char_version = $id_prefix . $id;

    if ($result['matched_langcode'] !== $current_langcode) {
      if (!empty($result['name_in_current_lang'])) {
        // $this->getLogger('gestion_documentaire')->notice(
        //   '[resolveAndRedirect] 🔄 Nom corrigé disponible: "@nom_original" (@lang_found) → "@nom_correct" (@lang_current) | action=@action',
        //   [
        //     '@nom_original' => $nom,
        //     '@lang_found'   => $result['matched_langcode'],
        //     '@nom_correct'  => $result['name_in_current_lang'],
        //     '@lang_current' => $current_langcode,
        //     '@action'       => $action,
        //   ]
        // );
      } else {
        // $this->getLogger('gestion_documentaire')->notice(
        //   '[resolveAndRedirect] ℹ️ Pas de trad en @lang_current pour "@nom", conservation du nom en @lang_found | action=@action',
        //   [
        //     '@nom'          => $nom,
        //     '@lang_current' => $current_langcode,
        //     '@lang_found'   => $result['matched_langcode'],
        //     '@action'       => $action,
        //   ]
        // );
      }
    }

    $path = $this->getPathForAction($action, $id_char_version);

    $this->getLogger('gestion_documentaire')->notice(
      '[resolveAndRedirect] ➡️ Sous-requête: ID=@id | action=@action | path=@path',
      [
        '@id'     => $id_char_version,
        '@action' => $action,
        '@path'   => $path,
      ]
    );

    return $this->createSubRequest($path);
  }

  /**
   * Retourne le path de destination selon l'action.
   */
  protected function getPathForAction($action, $id_char_version)
  {
    $paths = [
      'update'       => '/gestion_documentaire/update/%s/%s',
      'delete'       => '/gestion_documentaire/delete/%s/%s',
      'add_taxonomy' => '/gestion_documentaire/add/taxonomy/%s',
      'add_media'    => '/gestion_documentaire/add/media/%s',
      'duplicate'    => '/gestion_documentaire/add/media/%s/%s',
    ];

    $resource_type = strpos($id_char_version, 'taxonomy_') === 0 ? 'taxonomy' : 'media';

    switch ($action) {
      case 'update':
      case 'delete':
        return sprintf($paths[$action], $resource_type, $id_char_version);

      case 'duplicate':
        return sprintf($paths[$action], $id_char_version, $id_char_version);

      case 'add_taxonomy':
      case 'add_media':
        return sprintf($paths[$action], $id_char_version);

      default:
        throw new \InvalidArgumentException("Action non supportée : {$action}");
    }
  }

  /**
   * Route de debug pour afficher toutes les taxonomies.
   */
  public function debugTaxonomies()
  {
    $taxonomy_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $all_terms        = $taxonomy_storage->loadMultiple();
    $languages        = \Drupal::languageManager()->getLanguages();

    $output = '<h2>Liste des taxonomies et leurs traductions</h2>';

    foreach ($all_terms as $term) {
      $translations = [];
      foreach ($languages as $langcode => $language) {
        if ($term->hasTranslation($langcode)) {
          $translated = $term->getTranslation($langcode);
          // Afficher seulement les trads natives
          if ($translated->language()->getId() === $langcode) {
            $translations[] = "$langcode: " . $translated->getName();
          } else {
            $translations[] = "$langcode: (fallback → " . $translated->language()->getId() . ")";
          }
        }
      }

      $output .= '<div style="margin: 10px 0; padding: 10px; border: 1px solid #ccc;">';
      $output .= '<strong>TID ' . $term->id() . '</strong><br>';
      $output .= 'Traductions: ' . implode(' | ', $translations);
      $output .= '</div>';
    }

    return ['#markup' => $output];
  }
}
<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class RedirectRenderRessourceController extends ControllerBase
{

  /**
   * Heuristique simple : l'UA ressemble-t-il à un bot / outil automatisé ?
   */
  private static function isBot(?string $ua): bool
  {
    if ($ua === NULL || $ua === '') {
      // Pas de user-agent du tout = quasi toujours un script/outil
      return TRUE;
    }

    // Motifs courants de bots, crawlers et clients HTTP programmés
    $patterns = 'bot|crawler|spider|slurp|crawl|scrape|'
      . 'curl|wget|python-requests|python-urllib|go-http-client|'
      . 'java/|okhttp|httpclient|libwww-perl|axios|node-fetch|guzzle|'
      . 'facebookexternalhit|whatsapp|telegrambot|semrush|ahrefs|mj12|dotbot|'
      . 'bingbot|googlebot|yandex|baiduspider|duckduckbot|petalbot|applebot|'
      . 'headlesschrome|phantomjs|scrapy|zgrab|masscan|'
      . 'pdfmin|msh-';

    // Délimiteur ~ pour éviter le conflit avec le "/" présent dans "java/"
    return (bool) preg_match('~(' . $patterns . ')~i', $ua);
  }

  /**
   * Insère une ligne d'erreur dans gestion_documentaire_access_log.
   *
   * Équivalent du logAndThrow de RenderRessourceController, mais SANS exception :
   * ce contrôleur renvoie lui-même une Response 404, on veut juste incrémenter
   * le compteur d'erreur en base.
   */
  private function logAccessError(string $reason, string $media_id = ""): void
  {
    $account = \Drupal::currentUser();

    $tiers_id = \Drupal::database()->select('user__field_id', 'ufi')
      ->fields('ufi', ['field_id_value'])
      ->condition('ufi.entity_id', $account->id())
      ->execute()
      ->fetchField();

    \Drupal::database()->insert('gestion_documentaire_access_log')
      ->fields([
        'media_id'  => $media_id,
        'tiers_id'  => $tiers_id ?? '',
        'timestamp' => \Drupal::time()->getCurrentTime(),
        'action'    => 'error',
        'status'    => 'error',
        'message'   => $reason,
      ])
      ->execute();
  }

  public function content(?string $type = NULL, ?string $name = NULL, ?string $lang = NULL, ?string $action = NULL)
  {
    $language   = \Drupal::languageManager()->getCurrentLanguage();
    $connection = \Drupal::database('drupal9');
    $account    = \Drupal::currentUser();


    if($lang=="en"){
      $lang="uk";
    }
    // =========================================================
    //  REQUÊTE MEDIA
    // =========================================================
    $media_query = $connection->select('media_field_data', 'm')
      ->fields('m', ['mid', 'name', 'uid', 'created', 'changed']);
    $media_query->leftJoin('media_revision',               'mr',  'm.mid = mr.mid');
    $media_query->leftJoin('media__field_media_lang',      'mrl', 'mr.vid = mrl.revision_id');
    $media_query->leftJoin('media__field_media_reference', 'mrr', 'mr.vid = mrr.revision_id');
    $media_query->leftJoin('media__field_media_version',   'mrv', 'mr.vid = mrv.revision_id');

    // ── Jointure sur la taxonomie associée ──
    $media_query->leftJoin('media__field_taxonomie_associee', 'mta', 'm.mid = mta.entity_id');
    // ── Jointure sur le nom de la taxonomie (traduction) ──
    $media_query->leftJoin('taxonomy_term_field_data',        'ttfd', 'mta.field_taxonomie_associee_target_id = ttfd.tid');

    $media_query->fields('mr',   ['revision_log_message']);
    $media_query->fields('mrl',  ['field_media_lang_value']);
    $media_query->fields('mrr',  ['field_media_reference_value']);
    $media_query->fields('mrv',  ['field_media_version_value']);
    $media_query->addField('ttfd', 'name', 'taxonomy_name');

    // ── Filtre sur la traduction de taxonomie (remplace l'ancien filtre $type) ──
    if ($type !== NULL && $type !== '') {
      $tids = $connection->select('taxonomy_term_field_data', 't')
          ->fields('t', ['tid', 'name'])
          ->condition('t.name', $type, '=')  // exact uniquement
          ->execute()
          ->fetchAllKeyed();
  
      if (!empty($tids)) {
          $media_query->condition('mta.field_taxonomie_associee_target_id', array_keys($tids), 'IN');
      } else {
          // Fallback LIKE seulement si zéro résultat exact
          $media_query->condition('ttfd.name', '%' . $connection->escapeLike($type) . '%', 'LIKE');
      }
  }
    if ($lang !== NULL && $lang !== '') {
      $media_query->condition('mrl.field_media_lang_value', '%' . $connection->escapeLike($lang) . '%', 'LIKE');
    }
    if ($name !== NULL) {
      $or = $media_query->orConditionGroup()
        ->condition('m.name',                          '%' . $connection->escapeLike($name) . '%', 'LIKE')
        ->condition('mrr.field_media_reference_value', '%' . $connection->escapeLike($name) . '%', 'LIKE');

      if (str_starts_with($name, 'media_') && is_numeric(substr($name, 6))) {
        $or->condition('m.mid', (int) substr($name, 6), '=');
      }

      // if (str_starts_with($name, 'media_') && count(explode('_',substr($name, 6))==2 ) ) {
      //   $or->condition('concat(m.mid,"_",mrv.field_media_version_value)', substr($name, 6), '=');
      // }



      $media_query->condition($or);
    }

    $media_query->range(0, 1);
    $row = $media_query->execute()->fetchAssoc();

    // =========================================================
    //  REQUÊTE TAXONOMY SI MEDIA NON TROUVÉ
    // =========================================================
    if (!$row) {
      $taxonomy_query = $connection->select('taxonomy_term_field_data', 'ttfd')
        ->fields('ttfd', ['tid', 'name', 'created', 'changed']);
      $taxonomy_query->addField('ttr', 'revision_log_message');
      $taxonomy_query->leftJoin('taxonomy_term_revision', 'ttr', 'ttfd.tid = ttr.tid');

      if ($name !== NULL) {
        $taxonomy_query->condition('ttfd.name', '%' . $name . '%', 'LIKE');
      }

      $taxonomy_query->range(0, 1);
      $row = $taxonomy_query->execute()->fetchAssoc();

      if (!$row) {
        $request = \Drupal::request();
        $ua      = $request->headers->get('user-agent');

        // On ne logue pas si ça vient d'un bot / outil automatisé
        if (!self::isBot($ua)) {
          // \Drupal::logger('gestion_documentaire')->info(
          //   'Ressource introuvable — type: @type, name: @name, lang: @lang, action: @action | user: @user (uid @uid) | ip: @ip | referer: @referer | ua: @ua | path: @path',
          //   [
          //     '@type'    => $type   ?? 'NULL',
          //     '@name'    => $name   ?? 'NULL',
          //     '@lang'    => $lang   ?? 'NULL',
          //     '@action'  => $action ?? 'NULL',
          //     '@user'    => $account->getAccountName() ?: 'anonyme',
          //     '@uid'     => $account->id(),
          //     '@ip'      => $request->getClientIp(),
          //     '@referer' => $request->headers->get('referer') ?: 'aucun',
          //     '@ua'      => $ua ?: 'inconnu',
          //     '@path'    => $request->getPathInfo(),
          //   ]
          // );

          // Compteur d'erreur en base — même gate anti-bot que le watchdog ci-dessus.
          $this->logAccessError(
            sprintf(
              'Redirect introuvable — type=%s, name=%s, lang=%s, action=%s',
              $type   ?? 'NULL',
              $name   ?? 'NULL',
              $lang   ?? 'NULL',
              $action ?? 'NULL'
            ),
            $lang." / ".$type. " / ". $name
          );
        }

        // Introuvable = introuvable pour tout le monde, le login n'y changerait rien
        return new Response('', Response::HTTP_NOT_FOUND);
      }

      $id_char_version = 'taxonomy_' . $row['tid'];

      // ── Vérification accès taxonomy ──
      $access = AccessMediaController::access($account, $id_char_version);
      if (!$access->isAllowed()) {
        if ($account->isAnonymous()) {
          $destination = \Drupal::request()->getPathInfo();
          return new TrustedRedirectResponse('/extranet/fr/user/login?destination=' . urlencode($destination));
        }
        throw new AccessDeniedHttpException();
      }

      $controller = new RenderRessourceController();
      return $controller->content($id_char_version);

    } else {

      // =========================================================
      //  LOGIQUE POUR MEDIA TROUVÉ
      // =========================================================
      $media_name      = $row['name'];
      $taxonomy_name   = $row['taxonomy_name']               ?? '';
      $media_reference = $row['field_media_reference_value'] ?? '';
      $version         = !empty($row['field_media_version_value']) ? $row['field_media_version_value'] : 1;

      if (!empty($taxonomy_name) && str_starts_with(strtolower($media_name), strtolower($taxonomy_name))) {
        $url_suffix = !empty($name) ? $name : $media_name;
      } else {
        $pathinfo   = pathinfo($media_name);
        $name_part  = preg_replace('/_?\d+$/', '', $pathinfo['filename']);
        $url_suffix = $name_part;
      } 

      $id_char_version = 'media_' . $row['mid'] . '_' . $version;

      // \Drupal::logger('gestion_documentaire')->debug('data @identifier', ['@identifier' => $media_query]);
      // \Drupal::logger('gestion_documentaire')->debug('data2 @identifier', ['@identifier' => $id_char_version]);
      // \Drupal::logger('gestion_documentaire')->debug('dataAccount @identifier', ['@identifier' => $account]);

      // ── Vérification accès media ──
      $access = AccessMediaController::access($account, $id_char_version);

      if (!$access->isAllowed()) {
        if ($account->isAnonymous()) {
          $destination = \Drupal::request()->getPathInfo();
          return new TrustedRedirectResponse('/extranet/fr/user/login?destination=' . urlencode($destination));
        }
        throw new AccessDeniedHttpException();
      }

      $controller = new RenderRessourceController();
      return $controller->content(
        $id_char_version,
        $action === 'download',
        $url_suffix
      );
    }
  }
}
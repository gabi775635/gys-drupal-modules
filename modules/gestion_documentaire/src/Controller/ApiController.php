<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Drupal\user\Entity\User;

class ApiController extends ControllerBase {

  private const ALLOWED_TYPES = ['ex', 'er', 'man'];

  private const TYPE_MAP = [
    'ex'  => 'extrouble',
    'er'  => 'codeerreur',
    'man' => 'manual',
  ];

  /* ========================================================================
   * SESSION — récupère le compte courant (cookie de session)
   * ====================================================================== */

  private function currentAccount() {
    $account = User::load($this->currentUser()->id());

    if (!$account || $account->isAnonymous()) {
      return new JsonResponse(['error' => 'Non authentifié'], 401);
    }
    if (!$account->isActive()) {
      return new JsonResponse(['error' => 'Compte inactif'], 403);
    }

    return $account;
  }

  /* ========================================================================
   * RESOLVE TYPE — accepte clé courte ou label long, retourne les deux
   * ====================================================================== */

  private function resolveType(string $input): ?array {
    $input = strtolower(trim($input));

    if (isset(self::TYPE_MAP[$input])) {
      return ['key' => $input, 'label' => self::TYPE_MAP[$input]];
    }

    $flipped = array_flip(self::TYPE_MAP);
    if (isset($flipped[$input])) {
      return ['key' => $flipped[$input], 'label' => $input];
    }

    return NULL;
  }

  /* ========================================================================
   * CONNECT — retourne les types et langues disponibles pour une ref
   * ====================================================================== */

  public function connect(Request $request) {

    $body = json_decode($request->getContent(), TRUE) ?: $request->request->all();
    $ref  = trim($body['ref'] ?? '');

    if (!$ref) {
      return new JsonResponse(['error' => 'ref requis'], 400);
    }

    $account = $this->currentAccount();
    if ($account instanceof JsonResponse) {
      return $account;
    }

    $produitController = new ProduitController();

    $typesResponse = $produitController->getTypesByRef($ref);
    $allTypes      = json_decode($typesResponse->getContent(), TRUE) ?? [];

    $types = [];
    foreach ($allTypes as $typeKey) {
      if (!in_array(strtolower($typeKey), self::ALLOWED_TYPES, TRUE)) {
        continue;
      }
      $types[$typeKey] = self::TYPE_MAP[$typeKey];
    }

    $documents = [];
    foreach ($types as $typeKey => $label) {
      $fakeRequest = Request::create('/', 'GET');
      $docResponse = $produitController->getDocumentProduit($fakeRequest, $ref, $label);
      $data        = json_decode($docResponse->getContent(), TRUE);
      if (!empty($data)) {
        $documents[$typeKey] = array_keys($data);
      }
    }

    return new JsonResponse([
      'ref'       => $ref,
      'types'     => $types,
      'documents' => $documents,
    ]);
  }

  /* ========================================================================
   * CHECK — vérifie si un document existe pour ref + type + lang
   * ====================================================================== */

  public function check(Request $request) {

    $logger = \Drupal::logger('gestion_documentaire_api');

    $body     = json_decode($request->getContent(), TRUE) ?: $request->request->all();
    $ref      = trim($body['ref']  ?? '');
    $type_raw = trim($body['type'] ?? '');
    $lang     = trim($body['lang'] ?? 'fr');

    if (!$ref || !$type_raw) {
      return new JsonResponse(['error' => 'ref et type requis'], 400);
    }

    $type = $this->resolveType($type_raw);
    if (!$type) {
      return new JsonResponse(['exist' => FALSE], 404);
    }

    $account = $this->currentAccount();
    if ($account instanceof JsonResponse) {
      return $account;
    }

    // ── Vérification existence en BDD ──────────────────────────────────────
    $connection = \Drupal::database();

    $mid = $connection->query("
      SELECT m.mid
      FROM media_field_data m
      INNER JOIN (
        SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
      ) latest ON m.mid = latest.mid
      INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
      LEFT JOIN media__field_media_reference mrr ON mr.vid = mrr.revision_id
      LEFT JOIN media__field_media_type      mrt ON mr.vid = mrt.revision_id
      LEFT JOIN media__field_media_lang      mrl ON mr.vid = mrl.revision_id
      WHERE mrr.field_media_reference_value LIKE :ref
        AND mrt.field_media_type_value       LIKE :type
        AND mrl.field_media_lang_value        LIKE :lang
      LIMIT 1
    ", [
      ':ref'  => '%' . $ref . '%',
      ':type' => '%' . $type['label'] . '%',
      ':lang' => '%' . $lang . '%',
    ])->fetchField();

    if (!$mid) {
      return new JsonResponse(['exist' => FALSE], 404);
    }

    // ── Vérification des droits ────────────────────────────────────────────
    $id_char = $type['label'] . '__' . $ref . '__' . $lang;
    $access  = AccessMediaController::access($account, $id_char);

    if (!$access->isAllowed()) {
      $logger->warning('check — accès refusé uid=@uid ref=@ref type=@type lang=@lang', [
        '@uid'  => $account->id(),
        '@ref'  => $ref,
        '@type' => $type['label'],
        '@lang' => $lang,
      ]);
      return new JsonResponse(['exist' => FALSE], 403);
    }

    return new JsonResponse(['exist' => TRUE], 200);
  }

  /* ========================================================================
   * DOCUMENTS BY TYPE — retourne les documents d'un type si droits OK
   * ====================================================================== */

  public function documentsByType(Request $request) {

    $logger = \Drupal::logger('gestion_documentaire_api');

    $body     = json_decode($request->getContent(), TRUE) ?: $request->request->all();
    $ref      = trim($body['ref']  ?? '');
    $type_raw = trim($body['type'] ?? '');
    $lang     = trim($body['lang'] ?? '');

    if (!$ref || !$type_raw) {
      return new JsonResponse(['error' => 'ref et type requis'], 400);
    }

    $type = $this->resolveType($type_raw);
    if (!$type) {
      return new JsonResponse([
        'error'          => 'Type de document non reconnu ou non autorisé',
        'type_recu'      => $type_raw,
        'types_acceptes' => array_merge(array_keys(self::TYPE_MAP), array_values(self::TYPE_MAP)),
      ], 400);
    }

    $account = $this->currentAccount();
    if ($account instanceof JsonResponse) {
      return $account;
    }

    $logger->notice('documentsByType — uid=@uid ref=@ref type=@type lang=@lang', [
      '@uid'  => $account->id(),
      '@ref'  => $ref,
      '@type' => $type['label'],
      '@lang' => $lang ?: '(vide)',
    ]);

    $id_char = $type['label'] . '__' . $ref . '__' . ($lang ?: 'fr');
    $access  = AccessMediaController::access($account, $id_char);

    if (!$access->isAllowed()) {
      $logger->warning('Accès refusé — uid=@uid ref=@ref type=@type', [
        '@uid'  => $account->id(),
        '@ref'  => $ref,
        '@type' => $type['label'],
      ]);
      return new JsonResponse([
        'error' => 'Accès non autorisé au type "' . $type['key'] . '" (' . $type['label'] . ') pour la référence ' . $ref,
      ], 403);
    }

    $produitController = new ProduitController();
    $fakeRequest       = Request::create('/', 'GET');
    $docResponse       = $produitController->getDocumentProduit($fakeRequest, $ref, $type['label']);
    $data              = json_decode($docResponse->getContent(), TRUE);

    if (empty($data)) {
      return new JsonResponse(['error' => 'Aucun document trouvé pour ce type'], 404);
    }

    return new JsonResponse([
      'ref'       => $ref,
      'type'      => [
        'key'   => $type['key'],
        'label' => $type['label'],
      ],
      'documents' => $data,
    ]);
  }

  /* ========================================================================
   * SERVE — stream le PDF
   * ====================================================================== */

  public function serve(Request $request) {

    $logger = \Drupal::logger('gestion_documentaire_api');

    $body   = json_decode($request->getContent(), TRUE) ?: $request->request->all();
    $ref    = trim($body['ref']    ?? '');
    $type   = trim($body['type']   ?? '');
    $lang   = trim($body['lang']   ?? '');
    $action = trim($body['action'] ?? 'view');

    if (!$ref || !$type) {
      return new JsonResponse(['error' => 'ref et type requis'], 400);
    }

    $account = $this->currentAccount();
    if ($account instanceof JsonResponse) {
      return $account;
    }

    $id_char = $type . '__' . $ref . '__' . ($lang ?: 'fr');
    $access  = AccessMediaController::access($account, $id_char);

    if (!$access->isAllowed()) {
      $logger->warning('Accès refusé — uid=@uid ref=@ref type=@type lang=@lang', [
        '@uid'  => $account->id(),
        '@ref'  => $ref,
        '@type' => $type,
        '@lang' => $lang,
      ]);
      return new JsonResponse(['error' => 'Accès non autorisé'], 403);
    }

    $produitController = new ProduitController();
    $fakeRequest       = Request::create('/', 'GET');
    $docResponse       = $produitController->getDocumentProduit($fakeRequest, $ref, $type);
    $data              = json_decode($docResponse->getContent(), TRUE);

    if (empty($data)) {
      return new JsonResponse(['error' => 'Document introuvable'], 404);
    }

    if ($lang && isset($data[$lang])) {
      $lang_used = $lang;
    } elseif (isset($data['fr'])) {
      $lang_used = 'fr';
    } elseif (isset($data['uk'])) {
      $lang_used = 'uk';
    } else {
      $lang_used = array_key_first($data);
    }

    $connection = \Drupal::database();

    $mid = $connection->query("
      SELECT m.mid
      FROM media_field_data m
      INNER JOIN (
        SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
      ) latest ON m.mid = latest.mid
      INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
      LEFT JOIN media__field_media_reference mrr ON mr.vid = mrr.revision_id
      LEFT JOIN media__field_media_type      mrt ON mr.vid = mrt.revision_id
      LEFT JOIN media__field_media_lang      mrl ON mr.vid = mrl.revision_id
      WHERE mrr.field_media_reference_value LIKE :ref
        AND mrt.field_media_type_value       LIKE :type
        AND mrl.field_media_lang_value        LIKE :lang
      LIMIT 1
    ", [
      ':ref'  => '%' . $ref . '%',
      ':type' => '%' . $type . '%',
      ':lang' => '%' . $lang_used . '%',
    ])->fetchField();

    if (!$mid) {
      return new JsonResponse(['error' => 'Document introuvable (mid)'], 404);
    }

    $row = $connection->query("
      SELECT fm.uri, fm.filename, fm.filemime
      FROM media__field_media_document mfd
      LEFT JOIN file_managed fm ON fm.fid = mfd.field_media_document_target_id
      WHERE mfd.entity_id = :mid
      LIMIT 1
    ", [':mid' => $mid])->fetchObject();

    if (!$row || !$row->uri) {
      return new JsonResponse(['error' => 'Fichier introuvable'], 404);
    }

    $realPath = \Drupal::service('file_system')->realpath($row->uri);

    if (!$realPath || !file_exists($realPath)) {
      return new JsonResponse(['error' => 'Fichier inaccessible'], 404);
    }

    $mime     = $row->filemime ?: 'application/octet-stream';
    $filename = $row->filename ?: basename($realPath);
    $filesize = filesize($realPath);

    $disposition = $action === 'download'
      ? 'attachment; filename="' . $filename . '"'
      : 'inline; filename="' . $filename . '"';

    $response = new StreamedResponse(function() use ($realPath) {
      $handle = fopen($realPath, 'rb');
      if (!$handle) return;
      while (!feof($handle)) {
        echo fread($handle, 8192);
        flush();
      }
      fclose($handle);
    });

    $response->headers->set('Content-Type',        $mime);
    $response->headers->set('Content-Disposition', $disposition);
    $response->headers->set('Content-Length',      $filesize);
    $response->headers->set('Cache-Control',       'private, no-cache');

    return $response;
  }
}
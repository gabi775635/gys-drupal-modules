<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Database\Database;

class GetDataController extends ControllerBase
{
    
    // =========================================================================
    //  HELPER PRIVÉ — récupère tous les MIDs d'un même media_group_id
    //  Permet d'exclure toutes les versions du même groupe lors des checks.
    // =========================================================================
    private static function getMidsForGroup($connection, int $current_mid): array
    {
        if ($current_mid === 0) {
            return [0];
        }
        
        // Récupérer le group_id du média courant
        $group_id = $connection->query("
        SELECT field_media_group_id_value
        FROM media__field_media_group_id
        WHERE entity_id = :mid
        LIMIT 1
        ", [':mid' => $current_mid])->fetchField();
        
        if (!$group_id) {
            return [$current_mid];
        }
        
        // Récupérer tous les MIDs du même groupe
        $mids = $connection->query("
        SELECT entity_id
        FROM media__field_media_group_id
        WHERE field_media_group_id_value = :group_id
        ", [':group_id' => $group_id])->fetchCol();
        
        return !empty($mids) ? array_map('intval', $mids) : [$current_mid];
    }
    
    /**
    * Retourne toutes les versions existantes d'un media_group_id.
    * GET /gestion_documentaire/getMediaVersions?group_id=X&current_mid=Y
    */
    public function getMediaVersions(Request $request)
    {
        try {
            Database::setActiveConnection('drupal9');
            $connection = Database::getConnection();
            
            $group_id    = trim($request->query->get('group_id', ''));
            $current_mid = (int) $request->query->get('current_mid', 0);
            
            if ($group_id === '') {
                return new JsonResponse([]);
            }
            
            // Si current_mid=0 : on veut TOUT le groupe (cas nouvelle version)
            // Si current_mid>0 : on exclut le média courant (cas mise à jour)
            if ($current_mid > 0) {
                $rows = $connection->query("
                SELECT
                m.mid,
                m.name,
                CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version,
                COALESCE(mrv.field_media_version_value, 1) AS version
                FROM media_field_data m
                INNER JOIN media__field_media_group_id mrg ON m.mid = mrg.entity_id
                LEFT JOIN (
                    SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
                    ) latest ON m.mid = latest.mid
                    LEFT JOIN media__field_media_version mrv ON latest.max_vid = mrv.revision_id
                    WHERE mrg.field_media_group_id_value = :group_id
                    AND m.mid != :current_mid
                    ORDER BY mrv.field_media_version_value DESC
                    ", [
                        ':group_id'    => $group_id,
                        ':current_mid' => $current_mid,
                        ])->fetchAll(\PDO::FETCH_ASSOC);
                    } else {
                        // Tout le groupe, sans exclusion
                        $rows = $connection->query("
                        SELECT
                        m.mid,
                        m.name,
                        CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version,
                        COALESCE(mrv.field_media_version_value, 1) AS version
                        FROM media_field_data m
                        INNER JOIN media__field_media_group_id mrg ON m.mid = mrg.entity_id
                        LEFT JOIN (
                            SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
                            ) latest ON m.mid = latest.mid
                            LEFT JOIN media__field_media_version mrv ON latest.max_vid = mrv.revision_id
                            WHERE mrg.field_media_group_id_value = :group_id
                            ORDER BY mrv.field_media_version_value DESC
                            ", [
                                ':group_id' => $group_id,
                                ])->fetchAll(\PDO::FETCH_ASSOC);
                            }
                            
                            return new JsonResponse($rows ?: []);
                            
                        } catch (\Exception $e) {
                            return new JsonResponse(['error' => $e->getMessage()], 500);
                        }
                    }
                    
                    
                    // =========================================================================
                    //  getData — inchangé
                    // =========================================================================
                    
                    public function searchCategories(Request $request)
                    {
                        $text = trim($request->query->get('text', ''));
                        if ($text === '') {
                            return new JsonResponse([]);
                        }
                        try {
                            Database::setActiveConnection('gys');
                            $connection = Database::getConnection();
                            $query = "SELECT famSSFam, texte_fr 
                            FROM categories 
                            WHERE famSSFam IS NOT NULL AND famSSFam != '' 
                            AND texte_fr IS NOT NULL AND texte_fr != '' 
                            AND texte_fr LIKE :text
                            GROUP BY famSSFam, texte_fr 
                            LIMIT 50";
                            $stmt = $connection->prepare($query);
                            $stmt->execute([':text' => '%' . $text . '%']);
                            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                            return new JsonResponse($results);
                        } catch (\Exception $e) {
                            return new JsonResponse(['error' => $e->getMessage()], 500);
                        }
                    }
                    
                    public function getData(Request $request)
                    {
                        if ($request->isMethod('POST')) {
                            $reference_str = trim($request->request->get('reference', ''));
                            $type          = trim($request->request->get('type', ''));
                        } else {
                            $reference_str = trim($request->query->get('reference', ''));
                            $type          = trim($request->query->get('type', ''));
                        }
                        $references = array_filter(array_map('trim', explode(',', $reference_str)));
                        
                        if (empty($references)) {
                            return new JsonResponse([]);
                        }
                        
                        try {
                            Database::setActiveConnection('gys');
                            $connection = Database::getConnection();
                        } catch (\Exception $e) {
                            return new JsonResponse(['error' => 'Erreur connexion BDD : ' . $e->getMessage()], 500);
                        }
                        
                        $placeholders = [];
                        $params = [];
                        foreach ($references as $i => $ref) {
                            $ph = ':ref' . $i;
                            $placeholders[] = $ph;
                            $params[$ph] = $ref;
                        }
                        
                        $query = "
                        SELECT
                        p.reference,
                        MAX(c.texte_fr)  AS designation_fr,
                        MAX(c2.texte_fr) AS c2_texte_fr,
                        MAX(c.famSSFam)  AS fam,
                        MAX(c2.famSSFam) AS ssfam,
                        MAX(p.designation_fr) AS title,
                        min(p.site_gys) AS site_gys,
                        MAX(p.sav) AS sav
                        FROM produits AS p
                        INNER JOIN categories AS c  ON c.famSSFam  = p.famille
                        INNER JOIN categories AS c2 ON c2.famSSFam = p.ssfamille
                        AND p.reference IN (" . implode(',', $placeholders) . ")
                        GROUP BY p.reference
                        ";
                        
                        try {
                            $stmt = $connection->prepare($query);
                            $stmt->execute($params);
                            return new JsonResponse($stmt->fetchAll(\PDO::FETCH_ASSOC));
                        } catch (\Exception $e) {
                            return new JsonResponse(['error' => 'Erreur SQL : ' . $e->getMessage()], 500);
                        }
                    }
                    
                    
                    // =========================================================================
                    //  1. VÉRIFICATION HASH FICHIER
                    // =========================================================================
                    public function checkFileHashDuplicate(Request $request)
                    {
                        try {
                            Database::setActiveConnection('drupal9');
                            $connection = Database::getConnection();
                            
                            $entity_id = (int) $request->request->get('entity_id', 0);
                            
                            // Récupérer tous les MIDs du même groupe pour les exclure
                            $exclude_mids = self::getMidsForGroup($connection, $entity_id);
                            
                            $fid = (int) $request->request->get('fid', 0);
                            if ($fid > 0) {
                                $file_uri = $connection->query(
                                    "SELECT uri FROM file_managed WHERE fid = :fid",
                                    [':fid' => $fid]
                                    )->fetchField();
                                    
                                    if (!$file_uri) {
                                        return new JsonResponse(['status' => 0, 'message' => '', 'references' => [], 'media' => null]);
                                    }
                                    
                                    $file_system = \Drupal::service('file_system');
                                    $filepath    = $file_system->realpath($file_uri);
                                    $file_hash   = ($filepath && file_exists($filepath)) ? hash_file('sha256', $filepath) : null;
                                } else {
                                    $file      = $request->files->get('file') ?? $request->request->get('file');
                                    $file_hash = $this->getFileHash($file);
                                }
                                
                                // Récupérer le hash actuel du média
                                $current_hash = $connection->query("
                                SELECT field_media_hash_value
                                FROM media__field_media_hash
                                WHERE entity_id = :mid
                                LIMIT 1
                                ", [':mid' => $entity_id])->fetchField();
                                
                                if ($current_hash && $current_hash === $file_hash) {
                                    return new JsonResponse([
                                        'status'  => 1,
                                        'message' => 'Le fichier uploadé est identique au fichier actuel.',
                                    ]);
                                }
                                
                                if (!$file_hash) {
                                    return new JsonResponse(['status' => 0, 'message' => '', 'references' => [], 'media' => null]);
                                }
                                
                                $result = self::findDuplicateByHash($connection, $file_hash, $exclude_mids);
                                
                                if ($result) {
                                    $message = sprintf(
                                        "Un fichier identique existe déjà :\n" .
                                        "  Média     : %s\n"  .
                                        "  Dossier   : %s\n"  .
                                        "  Version   : %s\n"  .
                                        "  Type      : %s\n"  .
                                        "  Langue(s) : %s\n"  .
                                        "  Réf(s)    : %s\n\n".
                                        "Voulez-vous continuer ?",
                                        $result['name'],
                                        $result['parent_taxonomy'] ?? '—',
                                        $result['id_char_version'],
                                        $result['type']            ?? '—',
                                        $result['lang']            ?? '—',
                                        $result['reference']       ?? '—'
                                    );
                                    
                                    return new JsonResponse([
                                        'status'     => 1,
                                        'message'    => $message,
                                        'references' => array_filter(explode(', ', $result['reference'] ?? '')),
                                        'media'      => $result,
                                    ]);
                                }
                                
                                return new JsonResponse(['status' => 0, 'message' => '', 'references' => [], 'media' => null]);
                                
                            } catch (\Exception $e) {
                                return new JsonResponse(['error' => 'Fail : ' . $e->getMessage()], 500);
                            }
                        }
                        
                        
                        // =========================================================================
                        //  2. VÉRIFICATION NOM DU MÉDIA
                        // =========================================================================
                        public function checkMediaNameDuplicate(Request $request)
                        {
                            try {
                                Database::setActiveConnection('drupal9');
                                $connection = Database::getConnection();
                                
                                $name      = trim($request->request->get('name', ''));
                                $taxonomy  = trim($request->request->get('taxonomy', ''));
                                $entity_id = (int) $request->request->get('entity_id', 0);
                                
                                if ($name === '') {
                                    return new JsonResponse(['status' => 0, 'message' => '', 'media' => null]);
                                }
                                
                                // Exclure tous les MIDs du même groupe
                                $exclude_mids = self::getMidsForGroup($connection, $entity_id);
                                $placeholders = implode(',', array_fill(0, count($exclude_mids), '?'));
                                
                                $query = "
                                SELECT
                                m.name,
                                t.name AS parent_taxonomy,
                                CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version
                                FROM media_field_data m
                                INNER JOIN (
                                    SELECT mid, MAX(vid) AS max_vid
                                    FROM media_revision
                                    GROUP BY mid
                                    ) latest ON m.mid = latest.mid
                                    INNER JOIN media_revision mr            ON mr.vid  = latest.max_vid
                                    LEFT JOIN  media__field_media_version mrv ON mr.vid = mrv.revision_id
                                    LEFT JOIN  media__field_taxonomie_associee link ON m.mid = link.entity_id
                                    LEFT JOIN  taxonomy_term_field_data t   ON link.field_taxonomie_associee_target_id = t.tid
                                    WHERE m.name = ?
                                    AND m.mid NOT IN ($placeholders)
                                    ";
                                    
                                    $params = array_merge([$name], $exclude_mids);
                                    
                                    if ($taxonomy !== '') {
                                        $query   .= " AND t.name = ?";
                                        $params[] = $taxonomy;
                                    }
                                    
                                    $query .= " LIMIT 1";
                                    
                                    $row = $connection->query($query, $params)->fetchAssoc();
                                    
                                    if ($row) {
                                        $message = sprintf(
                                            'Un média portant le nom "%s" existe déjà dans le dossier "%s" (version : %s).',
                                            $row['name'],
                                            $row['parent_taxonomy'] ?? '—',
                                            $row['id_char_version']
                                        );
                                        return new JsonResponse(['status' => 1, 'message' => $message, 'media' => $row]);
                                    }
                                    
                                    return new JsonResponse(['status' => 0, 'message' => '', 'media' => null]);
                                    
                                } catch (\Exception $e) {
                                    return new JsonResponse(['error' => 'Fail : ' . $e->getMessage()], 500);
                                }
                            }
                            
                            
                            // =========================================================================
                            //  3. VÉRIFICATION EXISTENCE DOCUMENT (type + ref + lang)
                            // =========================================================================
                            public function checkDocumentExistence(Request $request)
                            {
                                try {
                                    Database::setActiveConnection('drupal9');
                                    $connection = Database::getConnection();
                                    
                                    $refs      = array_filter(array_map('trim', explode(',', $request->request->get('ref',  ''))));
                                    $langs     = array_filter(array_map('trim', explode(',', $request->request->get('lang', ''))));
                                    $type      = $request->request->get('type', '');
                                    $entity_id = (int) $request->request->get('entity_id', 0);
                                    
                                    // Exclure tous les MIDs du même groupe (évite les faux positifs inter-versions)
                                    $exclude_mids = self::getMidsForGroup($connection, $entity_id);
                                    $placeholders_excl = implode(',', array_fill(0, count($exclude_mids), '?'));
                                    
                                    $tab_file          = [];
                                    $file_exist        = 0;
                                    $conflicting_media = [];
                                    
                                    foreach ($refs as $ref) {
                                        foreach ($langs as $lang) {
                                            $query = "
                                            SELECT
                                            m.mid,
                                            m.name,
                                            CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version
                                            FROM media_field_data m
                                            INNER JOIN (
                                                SELECT mid, MAX(vid) AS max_vid
                                                FROM media_revision
                                                GROUP BY mid
                                                ) latest ON m.mid = latest.mid
                                                INNER JOIN media_revision mr              ON mr.vid = latest.max_vid
                                                INNER JOIN media__field_media_type    mrt ON mr.vid = mrt.revision_id
                                                LEFT JOIN  media__field_media_reference mrr ON mr.vid = mrr.revision_id
                                                LEFT JOIN  media__field_media_lang    mrl ON mr.vid = mrl.revision_id
                                                LEFT JOIN  media__field_media_version mrv ON mr.vid = mrv.revision_id
                                                WHERE m.mid NOT IN ($placeholders_excl)
                                                AND mrr.field_media_reference_value LIKE ?
                                                AND mrl.field_media_lang_value       LIKE ?
                                                AND mrt.field_media_type_value        = ?
                                                LIMIT 1
                                                ";
                                                
                                                $params = array_merge(
                                                    $exclude_mids,
                                                    ['%' . $ref . '%', '%' . $lang . '%', $type]
                                                );
                                                
                                                $stmt = $connection->query($query, $params);
                                                $row  = $stmt->fetchAssoc();
                                                
                                                $exists = !empty($row);
                                                $tab_file[$ref][$lang] = $exists ? 1 : 0;
                                                
                                                if ($exists) {
                                                    $file_exist = 1;
                                                    $icv = $row['id_char_version'];
                                                    if (!isset($conflicting_media[$icv])) {
                                                        $conflicting_media[$icv] = [
                                                            'id_char_version' => $icv,
                                                            'name'            => $row['name'],
                                                            'refs'            => [],
                                                            'langs'           => [],
                                                        ];
                                                    }
                                                    if (!in_array($ref, $conflicting_media[$icv]['refs'])) {
                                                        $conflicting_media[$icv]['refs'][] = $ref;
                                                    }
                                                    if (!in_array($lang, $conflicting_media[$icv]['langs'])) {
                                                        $conflicting_media[$icv]['langs'][] = $lang;
                                                    }
                                                }
                                            }
                                        }
                                        
                                        $lines = [];
                                        foreach ($tab_file as $ref => $langMap) {
                                            $existingLangs = array_keys(array_filter($langMap));
                                            if (!empty($existingLangs)) {
                                                $lines[] = $ref . ' : ' . implode(' / ', $existingLangs);
                                            }
                                        }
                                        $message = !empty($lines)
                                        ? "Des fichiers existent déjà pour :\n" . implode("\n", $lines)
                                        : '';
                                        
                                        return new JsonResponse([
                                            'tab_status'        => $tab_file,
                                            'status'            => $file_exist,
                                            'message'           => $message,
                                            'conflicting_media' => array_values($conflicting_media),
                                        ]);
                                        
                                    } catch (\Exception $e) {
                                        return new JsonResponse(['error' => 'Fail : ' . $e->getMessage()], 500);
                                    }
                                }
                                
                                
                                // =========================================================================
                                //  4. VALIDATION DES RÉFÉRENCES
                                // =========================================================================
                                public function validateReferenceNumbers(Request $request)
                                {
                                    Database::setActiveConnection('gys');
                                    $connection = Database::getConnection();
                                    
                                    $ref = trim($request->query->get('ref', ''));
                                    $terms = array_map('strtoupper', explode(',', $ref));
                                    
                                    $terms_status = [];
                                    $all_references = [];
                                    
                                    foreach ($terms as $term) {
                                        $queryExact = "SELECT DISTINCT p.reference
                                        FROM produits p
                                        INNER JOIN categories c  ON c.famSSFam  = p.famille
                                        INNER JOIN categories c2 ON c2.famSSFam = p.ssfamille
                                        WHERE (p.reference = '" . $term . "'
                                        OR c.famSSFam  = '" . $term . "'
                                        OR p.modele  = '" . $term . "'
                                        OR c2.famSSFam = '" . $term . "')";
                                        $exactRefs = $connection->query($queryExact)->fetchAll(\PDO::FETCH_COLUMN);
                                        
                                        if (!empty($exactRefs)) {
                                            $terms_status[$term] = 1;
                                            foreach ($exactRefs as $refProd) {
                                                if (!in_array($refProd, $all_references)) {
                                                    $all_references[] = $refProd;
                                                }
                                            }
                                            continue;
                                        }
                                        
                                        $queryLike = "SELECT DISTINCT p.reference
                                        FROM produits p
                                        WHERE (p.site_gys = -1 OR p.sav = -1)
                                        AND p.designation_fr LIKE '%" . $term . "%'";
                                        $likeRefs = $connection->query($queryLike)->fetchAll(\PDO::FETCH_COLUMN);
                                        
                                        if (!empty($likeRefs)) {
                                            $terms_status[$term] = 1;
                                            foreach ($likeRefs as $refProd) {
                                                if (!in_array($refProd, $all_references)) {
                                                    $all_references[] = $refProd;
                                                }
                                            }
                                        } else {
                                            $terms_status[$term] = 0;
                                        }
                                    }
                                    
                                    return new JsonResponse([
                                        'terms_status' => $terms_status,
                                        'references'   => $all_references,
                                        'status'       => (in_array(0, $terms_status) ? 0 : 1)
                                    ]);
                                }
                                
                                
                                // =========================================================================
                                //  5. VALIDATION DES LANGUES
                                // =========================================================================
                                public function validateLanguages(Request $request)
                                {
                                    $validCodes = [
                                        'fr','uk','us','de','es','pt','it','ru','cn','ar',
                                        'bg','cz','da','et','fi','el','hu','hr','is','jp',
                                        'nl','no','pl','rs','sk','sl','sw','tr','tw','ro',
                                        'vn','ko','ua','lt','lv',
                                    ];
                                    $validLookup = array_flip($validCodes);
                                    
                                    $lang = trim($request->query->get('lang', ''));
                                    if ($lang === '') {
                                        return new JsonResponse(['tab_status' => [], 'lang_status' => 0]);
                                    }
                                    
                                    $tab_lang = array_unique(array_filter(array_map(
                                        function($l) { return strtolower(trim($l)); },
                                        explode(',', $lang)
                                    )));
                                    
                                    $lang_status    = 1;
                                    $tab_lang_status = [];
                                    
                                    foreach ($tab_lang as $lg) {
                                        if (isset($validLookup[$lg])) {
                                            $tab_lang_status[$lg] = 1;
                                        } else {
                                            $tab_lang_status[$lg] = 0;
                                            $lang_status = 0;
                                        }
                                    }
                                    
                                    return new JsonResponse(['tab_status' => $tab_lang_status, 'lang_status' => $lang_status]);
                                }
                                
                                
                                // =========================================================================
                                //  getInformationProduct — inchangé
                                // =========================================================================
                                public function getInformationProduct(Request $request)
                                {
                                    try {
                                        Database::setActiveConnection('gys');
                                        $connection = Database::getConnection();
                                    } catch (\Exception $e) {
                                        return new JsonResponse(['error' => 'Erreur connexion BDD : ' . $e->getMessage()], 500);
                                    }
                                    
                                    if ($request->isMethod('POST')) {
                                        $content = json_decode($request->getContent(), true);
                                        $data = $content['data'] ?? '';
                                    } else {
                                        $data = $request->query->get('data', '');
                                    }
                                    
                                    $dataArray = array_filter(array_map('trim', explode(',', $data)));
                                    
                                    if (empty($dataArray)) {
                                        return new JsonResponse([]);
                                    }
                                    
                                    $placeholders1 = $placeholders2 = $placeholders3 = [];
                                    $binds = [];
                                    foreach ($dataArray as $i => $val) {
                                        $placeholders1[] = ":data1_$i"; $binds[":data1_$i"] = $val;
                                        $placeholders2[] = ":data2_$i"; $binds[":data2_$i"] = $val;
                                        $placeholders3[] = ":data3_$i"; $binds[":data3_$i"] = $val;
                                    }
                                    
                                    $query = "
                                    SELECT c.famSSFam as fam, c2.famSSFam as ssfam,
                                    p.reference, p.designation_fr as title, p.site_gys as site_gys , p.sav as sav
                                    FROM produits AS p
                                    INNER JOIN categories AS c  ON c.famSSFam  = p.famille
                                    INNER JOIN categories AS c2 ON c2.famSSFam = p.ssfamille
                                    WHERE (p.reference    IN (" . implode(',', $placeholders1) . ")
                                    OR  c.famSSFam     IN (" . implode(',', $placeholders2) . ")
                                    OR  c2.famSSFam    IN (" . implode(',', $placeholders3) . "))
                                    GROUP BY p.reference
                                    ORDER BY p.ssfamille DESC
                                    ";
                                    
                                    $stmt = $connection->prepare($query);
                                    $stmt->execute($binds);
                                    $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                                    
                                    return new JsonResponse($result);
                                }
                                
                                
                                // =========================================================================
                                //  MÉTHODE STATIQUE — appelable depuis Add/UpdateMediaForm
                                // =========================================================================
                                public static function checkHashFromFile($file, int $current_entity_id = 0): array
                                {
                                    if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                                        $filepath = $file->getRealPath();
                                    } elseif ($file instanceof \Drupal\file\Entity\File) {
                                        $filepath = \Drupal::service('file_system')->realpath($file->getFileUri());
                                    } elseif (is_string($file) && file_exists($file)) {
                                        $filepath = $file;
                                    } else {
                                        return ['exists' => false, 'message' => '', 'media' => null];
                                    }
                                    
                                    if (!$filepath || !file_exists($filepath)) {
                                        return ['exists' => false, 'message' => '', 'media' => null];
                                    }
                                    
                                    $hash = hash_file('sha256', $filepath);
                                    if (!$hash) {
                                        return ['exists' => false, 'message' => '', 'media' => null];
                                    }
                                    
                                    try {
                                        Database::setActiveConnection('drupal9');
                                        $connection   = Database::getConnection();
                                        // Exclure toutes les versions du même groupe
                                        $exclude_mids = self::getMidsForGroup($connection, $current_entity_id);
                                        $result       = self::findDuplicateByHash($connection, $hash, $exclude_mids);
                                    } catch (\Exception $e) {
                                        \Drupal::logger('gestion_documentaire')->error(
                                            'checkHashFromFile — erreur BDD : @msg', ['@msg' => $e->getMessage()]
                                        );
                                        return ['exists' => false, 'message' => '', 'media' => null];
                                    }
                                    
                                    if (!$result) {
                                        return ['exists' => false, 'message' => '', 'media' => null];
                                    }
                                    
                                    return [
                                        'exists'  => true,
                                        'message' => sprintf(
                                            'Un fichier identique existe déjà — Média : "%s" | Dossier : "%s" | Version : %s | Type : %s | Langue(s) : %s | Référence(s) : %s',
                                            $result['name'],
                                            $result['parent_taxonomy'] ?? '—',
                                            $result['id_char_version'],
                                            $result['type']            ?? '—',
                                            $result['lang']            ?? '—',
                                            $result['reference']       ?? '—'
                                        ),
                                        'media' => $result,
                                    ];
                                }
                                
                                
                                // =========================================================================
                                //  Méthode statique — vérifie si un nom existe déjà dans un dossier (TID)
                                // =========================================================================
                                public static function checkMediaNameExistsInFolder(string $name, int $tid, int $exclude_mid = 0): array
                                {
                                    if ($name === '' || $tid === 0) {
                                        return ['exists' => false, 'message' => '', 'media' => null];
                                    }
                                    
                                    try {
                                        Database::setActiveConnection('drupal9');
                                        $connection = Database::getConnection();
                                        
                                        // Exclure toutes les versions du même groupe
                                        $exclude_mids = self::getMidsForGroup($connection, $exclude_mid);
                                        $placeholders = implode(',', array_fill(0, count($exclude_mids), '?'));
                                        
                                        $query = "
                                        SELECT
                                        m.name,
                                        t.name AS parent_taxonomy,
                                        CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version
                                        FROM media_field_data m
                                        INNER JOIN (
                                            SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
                                            ) latest ON m.mid = latest.mid
                                            INNER JOIN media_revision mr            ON mr.vid  = latest.max_vid
                                            LEFT JOIN  media__field_media_version mrv ON mr.vid = mrv.revision_id
                                            LEFT JOIN  media__field_taxonomie_associee link ON m.mid = link.entity_id
                                            LEFT JOIN  taxonomy_term_field_data t   ON link.field_taxonomie_associee_target_id = t.tid
                                            WHERE m.name  = ?
                                            AND t.tid   = ?
                                            AND m.mid NOT IN ($placeholders)
                                            LIMIT 1
                                            ";
                                            
                                            $params = array_merge([$name, $tid], $exclude_mids);
                                            $row = $connection->query($query, $params)->fetchAssoc();
                                            
                                            if (!$row) {
                                                return ['exists' => false, 'message' => '', 'media' => null];
                                            }
                                            
                                            return [
                                                'exists'  => true,
                                                'message' => sprintf(
                                                    'Le nom "%s" est déjà utilisé dans le dossier "%s" (version : %s).',
                                                    $row['name'],
                                                    $row['parent_taxonomy'] ?? '—',
                                                    $row['id_char_version']
                                                ),
                                                'media' => $row,
                                            ];
                                        } catch (\Exception $e) {
                                            \Drupal::logger('gestion_documentaire')->error(
                                                'checkMediaNameExistsInFolder — erreur BDD : @msg', ['@msg' => $e->getMessage()]
                                            );
                                            return ['exists' => false, 'message' => '', 'media' => null];
                                        }
                                    }
                                    
                                    
                                    // =========================================================================
                                    //  REQUÊTE CENTRALE HASH — accepte maintenant un tableau de MIDs à exclure
                                    // =========================================================================
                                    private static function findDuplicateByHash($connection, string $file_hash, array $exclude_mids): ?array
                                    {
                                        // Garantir qu'on a au moins un élément pour le IN (évite SQL invalide)
                                        if (empty($exclude_mids)) {
                                            $exclude_mids = [0];
                                        }
                                        
                                        $placeholders = implode(',', array_fill(0, count($exclude_mids), '?'));
                                        
                                        $query = "
                                        SELECT
                                        m.name,
                                        t.name AS parent_taxonomy,
                                        CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version,
                                        mrt.field_media_type_value AS type,
                                        GROUP_CONCAT(DISTINCT mrl.field_media_lang_value      ORDER BY mrl.field_media_lang_value      SEPARATOR ', ') AS lang,
                                        GROUP_CONCAT(DISTINCT mrr.field_media_reference_value ORDER BY mrr.field_media_reference_value SEPARATOR ', ') AS reference
                                        FROM media_field_data m
                                        INNER JOIN (
                                            SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
                                            ) latest                               ON m.mid  = latest.mid
                                            INNER JOIN media_revision          mr  ON mr.vid = latest.max_vid
                                            INNER JOIN media__field_media_hash mrh ON mr.vid = mrh.revision_id
                                            LEFT JOIN  media__field_media_version mrv  ON mr.vid = mrv.revision_id
                                            LEFT JOIN  media__field_media_type    mrt  ON mr.vid = mrt.revision_id
                                            LEFT JOIN  media__field_media_lang    mrl  ON mr.vid = mrl.revision_id
                                            LEFT JOIN  media__field_media_reference mrr ON mr.vid = mrr.revision_id
                                            LEFT JOIN  media__field_taxonomie_associee link ON m.mid = link.entity_id
                                            LEFT JOIN  taxonomy_term_field_data   t    ON link.field_taxonomie_associee_target_id = t.tid
                                            WHERE mrh.field_media_hash_value = ?
                                            AND m.mid NOT IN ($placeholders)
                                            GROUP BY m.mid, m.name, t.name, mrv.field_media_version_value, mrt.field_media_type_value
                                            LIMIT 1
                                            ";
                                            
                                            $params = array_merge([$file_hash], $exclude_mids);
                                            $row = $connection->query($query, $params)->fetchAssoc();
                                            return $row ?: null;
                                        }
                                        
                                        
                                        // =========================================================================
                                        //  Calcule le hash SHA-256 d'un fichier uploadé ou d'un chemin absolu.
                                        // =========================================================================
                                        private function getFileHash($file): ?string
                                        {
                                            try {
                                                if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                                                    $filepath = $file->getRealPath();
                                                } elseif (is_string($file)) {
                                                    $filepath = $file;
                                                } else {
                                                    return null;
                                                }
                                                return file_exists($filepath) ? hash_file('sha256', $filepath) : null;
                                            } catch (\Exception $e) {
                                                return null;
                                            }
                                        }
                                    }
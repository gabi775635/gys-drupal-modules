<?php

namespace Drupal\gestion_documentaire\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Database\Database;

class ProduitController {

  public function getDocumentProduit(Request $request, $ref = NULL, $type = NULL) {
    $account = \Drupal::currentUser();

    if (!$ref || !$type) {
      return new JsonResponse(['error' => 'Reference or type missing'], 400);
    }

    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection('drupal9');

    $priorityLangs = ['fr', 'uk', 'us', 'de', 'es', 'it', 'ru', 'zh'];
    $orderLangs    = "'" . implode("','", $priorityLangs) . "'";

    // Sous-requête dernière révision
    $subquery = $connection->select('media_revision', 'mr_sub');
    $subquery->addField('mr_sub', 'mid');
    $subquery->addExpression('MAX(mr_sub.vid)', 'max_vid');
    $subquery->groupBy('mr_sub.mid');

    $query = $connection->select('media__field_media_reference', 'mrr');
    $query->innerJoin('media_field_data', 'm', 'mrr.entity_id = m.mid');
    $query->innerJoin($subquery, 'latest', 'm.mid = latest.mid');
    $query->innerJoin('media_revision', 'mr', 'm.mid = mr.mid AND mr.vid = latest.max_vid');
    $query->innerJoin('media__field_media_type', 'mrt', 'mr.vid = mrt.revision_id AND mrt.field_media_type_value = :type', [':type' => $type]);
    $query->leftJoin('media__field_media_lang',     'mrl', 'mr.vid = mrl.revision_id');
    $query->leftJoin('media__field_media_version',  'mrv', 'mr.vid = mrv.revision_id');
    $query->leftJoin('media__field_media_filename', 'mfn', 'mr.vid = mfn.revision_id');

    $query->fields('m', ['mid', 'name']);
    $query->addField('mrt', 'field_media_type_value', 'type');
    $query->addField('mrl', 'field_media_lang_value',  'lang');
    $query->addField('mrv', 'field_media_version_value', 'version');
    $query->addField('mfn', 'field_media_filename_value', 'filename');
    $query->addField('mr',  'vid', 'revision_id');
    $query->addExpression("CONCAT(m.name)", 'full_path');

    $query->condition('mrr.field_media_reference_value', '%' . $ref . '%', 'LIKE');

    $query->addExpression("FIELD(mrl.field_media_lang_value, $orderLangs)", 'lang_order');
    $query->orderBy('lang_order', 'ASC');
    $query->orderBy('revision_id', 'DESC');

    $results = $query->execute()->fetchAll();
    // var_dump($results);
    $data = [];

    foreach ($results as $row) {
      $langs       = $row->lang ? array_map('trim', explode(',', $row->lang)) : [''];
      $version_val = $row->version ?? 1;

      foreach ($langs as $lang) {
        if ($type === 'aide_technique' || $type == 'document_pointeuse' || $type == 'fds') {
          $rawName  = pathinfo($row->full_path, PATHINFO_FILENAME);
          // Le label vient désormais de field_media_filename_value
          $fileName = pathinfo($row->filename ?? $row->full_path, PATHINFO_FILENAME);
          $label    = preg_replace('/_\d+$/', '', $fileName) . '_' . $ref;
          $path     = '/extranet/document/' . $type . '/' . $rawName . '/' . $lang;
          if($type=='fds'){
            $label    =   $row->filename;
          }
          if (!isset($data[$lang])) {
            $data[$lang] = [];
          }

          $data[$lang][] = [
            'label'   => $label,
            'path'    => $path,
            'version' => $version_val,
          ];
        } else {
          $path = '/extranet/document/' . $type . '/' . $ref . '/' . $lang;

          if (!isset($data[$lang])) {
            $data[$lang] = ['path' => $path, 'version' => $version_val];
            continue;
          }
          if ((int) $version_val > (int) $data[$lang]['version']) {
            $data[$lang] = ['path' => $path, 'version' => $version_val];
          }
        }
      }
    }

    // Nettoyage du format de sortie
    foreach ($data as $lang => $value) {
      if ($type == 'aide_technique' || $type == 'document_pointeuse' || $type == 'fds') {
        usort($data[$lang], function ($a, $b) {
          $cmp = strcmp($a['label'], $b['label']);
          if ($cmp !== 0) return $cmp;
          return (int) $b['version'] <=> (int) $a['version'];
        });
        foreach ($data[$lang] as &$item) {
          unset($item['version']);
        }
      } else {
        $data[$lang] = $value['path'];
      }
    }

    // Dupliquer us depuis en/uk si absent
    $sourceLang = null;
    if (isset($data['en'])) {
      $sourceLang = 'en';
    } elseif (isset($data['uk'])) {
      $sourceLang = 'uk';
    }

    if ($sourceLang && !isset($data['us']) && ($type == 'datasheet' || $type == 'manual')) {
      $newData = [];
      foreach ($data as $l => $value) {
        $newData[$l] = $value;
        if ($l === $sourceLang) {
          $newData['us'] = $data[$sourceLang];
        }
      }
      $data = $newData;
    }

    // Tri final
    $remainingLangs = array_diff(array_keys($data), $priorityLangs);
    sort($remainingLangs);
    $langOrder  = array_merge($priorityLangs, $remainingLangs);
    $sortedData = [];
    foreach ($langOrder as $langCode) {
      if (isset($data[$langCode])) {
        $sortedData[$langCode] = $data[$langCode];
      }
    }

    return new JsonResponse($sortedData);
  }

  public function getTypesByRef(string $ref) {
    $connection = Database::getConnection('drupal9');

    $entity_query = $connection->select('media__field_media_reference', 'mrr');
    $entity_query->fields('mrr', ['entity_id']);
    $entity_query->condition('mrr.field_media_reference_value', '%' . $ref . '%', 'LIKE');
    $entity_ids = $entity_query->execute()->fetchCol();

    if (empty($entity_ids)) {
      return new JsonResponse([]);
    }

    $subquery = $connection->select('media_revision', 'mr_sub');
    $subquery->addField('mr_sub', 'mid');
    $subquery->addExpression('MAX(mr_sub.vid)', 'max_vid');
    $subquery->groupBy('mr_sub.mid');

    $type_query = $connection->select('media_field_data', 'm');
    $type_query->innerJoin('media_revision',          'mr',     'm.mid = mr.mid');
    $type_query->innerJoin('media__field_media_type', 'mrt',    'mr.vid = mrt.revision_id');
    $type_query->innerJoin($subquery,                 'latest', 'mr.mid = latest.mid AND mr.vid = latest.max_vid');
    $type_query->addField('mrt', 'field_media_type_value', 'type');
    $type_query->condition('m.mid', $entity_ids, 'IN');
    $type_query->distinct();

    $type_results = $type_query->execute()->fetchCol();
    // var_dump($type_results);
    $types_found = [];
    foreach ($type_results as $t) {
      switch (strtolower($t)) {
        case 'abaque':             $types_found['cc']     = true; break;
        case 'nomenclature':       $types_found['pd']     = true; break;
        case 'shower':
        case 'codeerreur':         $types_found['er']     = true; break;
        case 'ex':
        case 'extrouble':          $types_found['ex']     = true; break;
        case 'in':
        case 'introuble':          $types_found['in']     = true; break;
        case 'aide_technique':     $types_found['repair'] = true; break;
        case 'document_pointeuse': $types_found['docpti'] = true; break;
        case 'maj':                $types_found['maj']    = true; break;
        case 'ft':
        case 'datasheet':          $types_found['ft']     = true; break;
        case 'man':
        case 'manual':             $types_found['man']    = true; break;
        case 'releasenote':        $types_found['rn']     = true; break;
        case 'additional_manual':  $types_found['adman']  = true; break;
        case 'fds':                $types_found['fds']    = true; break;
        case 'notice_ihm':         $types_found['ihmman'] = true; break;
        default:                   $types_found[$t]       = true;
      }
    }

    return new JsonResponse(array_keys($types_found));
  }
}
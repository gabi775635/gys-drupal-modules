<?php

namespace Drupal\gestion_documentaire\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\Entity\Media;
use Drupal\Core\Database\Database;
use Drupal\taxonomy\Entity\Term;

class RefLangForm extends FormBase {

    /**
     * Helper: Récupère le chemin complet d'une taxonomie en remontant la hiérarchie
     * Alternative à la requête SQL récursive CTE
     */
    private function getTaxonomyFullPath($tid) {
        if (!$tid) {
            return '';
        }
        
        $path_parts = [];
        $current_tid = $tid;
        
        // Remonter la hiérarchie jusqu'à la racine
        while ($current_tid) {
            $term = Term::load($current_tid);
            if (!$term) {
                break;
            }
            
            // Ajouter le nom au début du chemin
            array_unshift($path_parts, $term->getName());
            
            // Récupérer le parent
            $parent_field = $term->get('parent');
            if ($parent_field->isEmpty()) {
                break;
            }
            
            $parent = $parent_field->first();
            $current_tid = $parent ? $parent->target_id : null;
            
            // Si parent = 0, on est à la racine
            if ($current_tid == 0) {
                break;
            }
        }
        
        return implode('/', $path_parts);
    }

    /**
     * Récupère les données nécessaires d'un média sans passer par la vue
     * 
     * @param int $media_id
     * @return array|null
     */
    private function getMediaDataDirect($media_id) {
        $media = Media::load($media_id);
        if (!$media) {
            return null;
        }

        Database::setActiveConnection();
        $connection = Database::getConnection('drupal9');

        // Récupération des données depuis les tables directement
        $query = "
            SELECT 
                m.mid AS entity_id,
                mrt.field_media_type_value AS type,
                mrh.field_media_hash_value AS hash,
                link.field_taxonomie_associee_target_id AS taxonomy_id
            FROM media_field_data m
            LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
            LEFT JOIN media_revision mr ON m.mid = mr.mid AND m.vid = mr.vid
            LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
            LEFT JOIN media__field_media_hash mrh ON mr.vid = mrh.revision_id
            WHERE m.mid = :mid
            LIMIT 1
        ";

        $result = $connection->query($query, [':mid' => $media_id])->fetchAssoc();

        if (!$result) {
            return null;
        }

        // Calculer le full_path via l'API Drupal
        $full_path = '';
        if (!empty($result['taxonomy_id'])) {
            $full_path = $this->getTaxonomyFullPath($result['taxonomy_id']);
        }

        return [
            'entity_id' => $result['entity_id'],
            'type' => $result['type'] ?? '',
            'hash' => $result['hash'] ?? '',
            'full_path' => $full_path,
        ];
    }

    public function getFormId() {
        return 'gestion_documentaire_ref_lang_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state, $media_id = NULL) {
        if (!$media_id) {
            $form['#markup'] = $this->t('ID média manquant.');
            return $form;
        }

        // Récupérer les données directement depuis les tables (sans vue)
        $ressource = $this->getMediaDataDirect($media_id);
        
        if (!$ressource) {
            $form['#markup'] = $this->t('Média introuvable.');
            return $form;
        }

        $media = Media::load($media_id);

        $hidden = ($ressource['type'] === "abaque") ? [] : ['hidden' => 'hidden'];
        $hidden = [];
        
        $form['ref_section'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Reference table'),
            '#attributes' => $hidden,
        ];
        
        // Container flex
        $form['ref_section']['input_wrapper'] = [
            '#type' => 'container',
            '#attributes' => [
                'style' => 'display: flex; align-items: center; gap: 0.5rem;',
            ],
        ];
        
        $form['ref_section']['input_wrapper']['inputDataRef'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Family / sub-family / reference'),
            '#size' => 20,
            '#attributes' => ['id' => 'inputDataRef'],
        ];
        
        $form['ref_section']['input_wrapper']['addValueRef'] = [
            '#type' => 'button',
            '#value' => $this->t('+'),
            '#attributes' => [
                'id' => 'addValueRef',
                'style' => 'line-height:1;vertical-align:middle;',
            ],
        ];

        $form['ref_section']['input_wrapper']['searchCategories'] = [
            '#type' => 'button',
            '#value' => $this->t('Search by category'),
            '#attributes' => [
                'id' => 'searchCategoriesBtn',
                'style' => 'line-height:1;vertical-align:middle; margin-left:5px;',
                'title' => $this->t('Rechercher une catégorie par texte'),
            ],
        ];
        
        $form['ref_section']['ref_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'ref_js_data', 'readonly' => 'readonly'],
        ];
        
        $form['ref_section']['hash_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'hash_js_data', 'readonly' => 'readonly'],
            '#value' => $ressource['hash'],
        ];
        
        $form['ref_section']['type_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'type_js_data', 'readonly' => 'readonly'],
            '#value' => $ressource['type'],
        ];
        
        $form['ref_section']['full_path_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'full_path_js_data', 'readonly' => 'readonly'],
            '#value' => $ressource['full_path'],
        ];
        
        $form['ref_section']['entity_id_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'entity_id_js_data', 'readonly' => 'readonly'],
            '#value' => $ressource['entity_id'],
        ];
        
        $form['ref_section']['tableDataRef'] = [
            '#type' => 'table',
            '#header' => [
                $this->t('Reference'),
                $this->t('Delete'),
            ],
            '#empty' => $this->t('None reference'),
            '#attributes' => ['id' => 'tableDataRef', 'class' => ['tableData']],
        ];
        
        // -----------------------------
        // Tableau des langues
        // -----------------------------
        $form['lang_section'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Language table'),
        ];
        
        // Container flex
        $form['lang_section']['input_wrapper'] = [
            '#type' => 'container',
            '#attributes' => [
                'style' => 'display: flex; align-items: center; gap: 0.5rem;',
            ],
        ];
        
        $form['lang_section']['input_wrapper']['inputDataLang'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Language code '.'(fr / us / it ...)' ),
            '#size' => 20,
            '#attributes' => ['id' => 'inputDataLang'],
        ];
        
        $form['lang_section']['input_wrapper']['addValueLang'] = [
            '#type' => 'button',
            '#value' => $this->t('+'),
            '#attributes' => [
                'id' => 'addValueLang',
                'style' => 'line-height:1;vertical-align:middle;',
            ],
        ];
        
        $form['lang_section']['lang_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'lang_js_data', 'readonly' => 'readonly'],
        ];
        
        $form['lang_section']['tableDataLang'] = [
            '#type' => 'table',
            '#header' => [
                $this->t('Language'),
                $this->t('Delete'),
            ],
            '#empty' => $this->t('Aucune langue ajoutée.'),
            '#attributes' => ['id' => 'tableDataLang', 'class' => ['tableData']],
        ];

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state) {
        $media_id = $form_state->getValue('media_id');
        $media = Media::load($media_id);

        if (!$media) {
            $this->messenger()->addError($this->t('Média introuvable.'));
            return;
        }

        $ref_js_data  = $form_state->getValue('ref_js_data');
        $lang_js_data = $form_state->getValue('lang_js_data');

        $changes = $this->saveRefLangData($media, $ref_js_data, $lang_js_data);

        if ($changes['modif_ref'] || $changes['modif_lang']) {
            $this->messenger()->addStatus($this->t('Références et langues mises à jour.'));
        }
    }

    /**
     * Construit les champs de référence et langue pour être réutilisés dans d'autres formulaires
     * VERSION OPTIMISÉE avec initialisation des langues existantes
     */
    public function buildRefLangFields(array &$form, int $media_id, array $ressource = null) {
        // Si $ressource n'est pas fourni ET qu'on a un media_id valide, le récupérer
        if ($ressource === null && $media_id > 0) {
            $ressource = $this->getMediaDataDirect($media_id);
            if (!$ressource) {
                \Drupal::messenger()->addError($this->t('Impossible de charger les données du média.'));
                return $form;
            }
        }
        
        //  Si $ressource est toujours null, initialiser un tableau vide
        if ($ressource === null) {
            $ressource = [
                'type' => '',
                'hash' => '',
                'full_path' => '',
                'entity_id' => 0,
            ];
        }

        // Récupérer le média pour avoir les langues actuelles
        $media = Media::load($media_id);
        $current_langs_string = '';
        $current_langs_array = [];
        if ($media) {
            $current_langs_string = $media->get('field_media_lang')->value ?? '';
            $current_langs_array = array_filter(array_map('trim', explode(',', $current_langs_string)));
        }

        $hidden = ($ressource['type'] === "abaque") ? [] : ['hidden' => 'hidden'];
        $hidden = [];
        
        $form['ref_section'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Reference table'),
            '#attributes' => $hidden,
        ];
        
        // Container flex
        $form['ref_section']['input_wrapper'] = [
            '#type' => 'container',
            '#attributes' => [
                'style' => 'display: flex; align-items: center; gap: 0.5rem;',
            ],
        ];
        
        $form['ref_section']['input_wrapper']['inputDataRef'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Family / sub family / reference'),
            '#size' => 20,
            '#attributes' => ['id' => 'inputDataRef'],
        ];
        
        $form['ref_section']['input_wrapper']['addValueRef'] = [
            '#type' => 'button',
            '#value' => $this->t('+'),
            '#attributes' => [
                'id' => 'addValueRef',
                'style' => 'line-height:1;vertical-align:middle;',
            ],
        ];
        
        $form['ref_section']['input_wrapper']['searchCategories'] = [
            '#type' => 'button',
            '#value' => $this->t('Search by category'),
            '#attributes' => [
                'id' => 'searchCategoriesBtn',
                'style' => 'line-height:1;vertical-align:middle; margin-left:5px;',
                'title' => $this->t('Rechercher une catégorie par texte'),
            ],
        ];
        
        $form['ref_section']['ref_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'ref_js_data', 'readonly' => 'readonly'],
        ];
        
        $form['ref_section']['hash_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'hash_js_data', 'readonly' => 'readonly'],
            '#value' => $ressource['hash'] ?? '',
        ];
        
        $form['ref_section']['type_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'type_js_data', 'readonly' => 'readonly'],
            '#value' => $ressource['type'] ?? '',
        ];
        
        $form['ref_section']['full_path_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'full_path_js_data', 'readonly' => 'readonly'],
            '#value' => $ressource['full_path'] ?? '',
        ];
        
        $form['ref_section']['entity_id_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'entity_id_js_data', 'readonly' => 'readonly'],
            '#value' => $ressource['entity_id'] ?? $media_id,
        ];
        
        $form['ref_section']['tableDataRef'] = [
            '#type' => 'table',
            '#header' => [
                $this->t('Reference'),
                $this->t('Delete'),
            ],
            '#empty' => $this->t('No references added.'),
            '#attributes' => ['id' => 'tableDataRef', 'class' => ['tableData']],
        ];
        
        // -----------------------------
        // Tableau des langues
        // -----------------------------
        $form['lang_section']['languages'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Selection of languages'),
            '#tree' => TRUE,
        ];
        
        $form['lang_section']['lang_js_data'] = [
            '#type' => 'hidden',
            '#attributes' => ['id' => 'lang_js_data'],
            '#default_value' => $current_langs_string,  // ← changement ici
        ];
        

        
        // Tableau des langues disponibles
        $languages = [
            'fr' => ['fr' => 'français', 'en' => 'french'],
            'uk' => ['fr' => 'anglais', 'en' => 'english'],
            'de' => ['fr' => 'allemand', 'en' => 'german'],
            'it' => ['fr' => 'italien', 'en' => 'italian'],
            'es' => ['fr' => 'espagnol', 'en' => 'spanish'],
            'ru' => ['fr' => 'russe', 'en' => 'russian'],
            'ar' => ['fr' => 'arabe', 'en' => 'arabic'],
            'bg' => ['fr' => 'bulgare', 'en' => 'bulgarian'],
            'zh' => ['fr' => 'chinois', 'en' => 'chinese'],
            'ko' => ['fr' => 'coréen', 'en' => 'korean'],
            'hr' => ['fr' => 'croate', 'en' => 'croatian'],
            'da' => ['fr' => 'danois', 'en' => 'danish'],
            'et' => ['fr' => 'estonien', 'en' => 'estonian'],
            'fi' => ['fr' => 'finnois', 'en' => 'finnish'],
            'el' => ['fr' => 'grec', 'en' => 'greek'],
            'hu' => ['fr' => 'hongrois', 'en' => 'hungarian'],
            'is' => ['fr' => 'islandais', 'en' => 'icelandic'],
            'ja' => ['fr' => 'japonais', 'en' => 'japanese'],
            'lv' => ['fr' => 'letton', 'en' => 'latvian'],
            'lt' => ['fr' => 'lituanien', 'en' => 'lithuanian'],
            'nl' => ['fr' => 'néerlandais', 'en' => 'dutch'],
            'no' => ['fr' => 'norvégien', 'en' => 'norwegian'],
            'pl' => ['fr' => 'polonais', 'en' => 'polish'],
            'pt' => ['fr' => 'portugais', 'en' => 'portuguese'],
            'ro' => ['fr' => 'roumain', 'en' => 'romanian'],
            'sk' => ['fr' => 'slovaque', 'en' => 'slovak'],
            'sr' => ['fr' => 'serbe', 'en' => 'serbian'],
            'sl' => ['fr' => 'slovénie', 'en' => 'slovenia'],
            'sv' => ['fr' => 'suédois', 'en' => 'swedish'],
            'cs' => ['fr' => 'tchèque', 'en' => 'czech'],
            'tr' => ['fr' => 'turc', 'en' => 'turkish'],
            'ua' => ['fr' => 'ukrainien', 'en' => 'ukrainian'],
            'vn' => ['fr' => 'vietnamien', 'en' => 'vietnamese'],
        ];
       
        // Langue du site
        $site_lang = \Drupal::languageManager()->getCurrentLanguage()->getId();
        
        // Container flex pour afficher sur une ligne
        $form['lang_section']['languages']['wrapper'] = [
            '#type' => 'container',
            '#attributes' => [
                'style' => '
                    display: grid;
                    grid-template-rows: repeat(6, auto);
                    grid-auto-flow: column;
                    gap: 10px 30px;
                ',
            ],
        ];
        
        foreach ($languages as $code => $lang) {
            $label = ($site_lang === 'fr') ? $lang['fr'] : $lang['en'];
            $flag_code = $code;
            $flag_url = "https://planet.gys.fr/img/common/flags/" . $flag_code . ".png";
            
            $form['lang_section']['languages']['wrapper'][$code] = [
                '#type' => 'checkbox',
                '#return_value' => $code,
                '#default_value' => in_array($code, $current_langs_array) ? $code : NULL,  // COCHE les langues existantes
                '#title' => '
                    <span style="display:flex; align-items:center; gap:3px;">
                        <img src="' . $flag_url . '" width="18" height="12" style="object-fit:cover;">
                        ' . ucfirst($label) . '
                    </span>',
                '#title_display' => 'after',
            ];
        }
        
        // Bouton submit (optionnel, selon le contexte)
        $form['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Envoyer'),
        ];
        
        return $form;
    }

    /**
     * Met à jour les références et langues d'un média.
     *
     * @param \Drupal\media\Entity\Media $media
     *   L'entité Media à mettre à jour.
     * @param string $ref_js_data
     *   Données JSON ou chaîne de références séparées par des virgules.
     * @param string $lang_js_data
     *   Données JSON ou chaîne de langues séparées par des virgules.
     *
     * @return array
     *   Tableau associatif avec les clés 'modif_ref', 'modif_lang',
     *   'deleted_langs', 'deleted_refs', 'added_langs', 'added_refs'.
     */
    public function saveRefLangData(Media $media, $ref_js_data, $lang_js_data) {
        $modif_ref = 0;
        $modif_lang = 0;
        $deleted_refs = [];
        $added_refs = [];
        $deleted_langs = [];
        $added_langs = [];

        // -----------------------------
        // Gestion des références
        // -----------------------------
        // \Drupal::logger('gestion_documentaire')->notice('=== DEBUT REFERENCES ===');
        // \Drupal::logger('gestion_documentaire')->notice('ref_js_data brut: @data', ['@data' => $ref_js_data]);

        $current_refs_string = $media->get('field_media_reference')->value ?? '';
        $current_refs_array = array_filter(array_map('trim', explode(',', $current_refs_string)));
        // \Drupal::logger('gestion_documentaire')->notice('Références actuelles: @refs', ['@refs' => json_encode($current_refs_array)]);

        if ($ref_js_data !== null && $ref_js_data !== '') {
            // Tentative de décodage JSON
            $ref_data = json_decode($ref_js_data, true);
            // \Drupal::logger('gestion_documentaire')->notice('json_last_error: @error', ['@error' => json_last_error()]);

            if (json_last_error() === JSON_ERROR_NONE && is_array($ref_data)) {
                // \Drupal::logger('gestion_documentaire')->notice('Mode JSON: @data', ['@data' => json_encode($ref_data)]);
                $ref_array = array_column($ref_data, 'reference');
                $ref_array = array_filter(array_map('trim', $ref_array));
                // \Drupal::logger('gestion_documentaire')->notice('Références extraites: @refs', ['@refs' => json_encode($ref_array)]);
            } else {
                // \Drupal::logger('gestion_documentaire')->notice('Mode CSV: @data', ['@data' => $ref_js_data]);
                $ref_array = array_filter(array_map('trim', explode(',', $ref_js_data)));
                // \Drupal::logger('gestion_documentaire')->notice('Références extraites: @refs', ['@refs' => json_encode($ref_array)]);
            }

            // Détecter suppressions et ajouts
            $deleted_refs = array_diff($current_refs_array, $ref_array);
            $added_refs = array_diff($ref_array, $current_refs_array);
            // \Drupal::logger('gestion_documentaire')->notice('Références supprimées: @deleted', ['@deleted' => json_encode($deleted_refs)]);
            // \Drupal::logger('gestion_documentaire')->notice('Références ajoutées: @added', ['@added' => json_encode($added_refs)]);

            if ($ref_array !== $current_refs_array) {
                $new_string = empty($ref_array) ? '' : implode(',', $ref_array);
                $media->set('field_media_reference', $new_string);
                $modif_ref = 1;
                // \Drupal::logger('gestion_documentaire')->notice('Mise à jour des références: @new', ['@new' => $new_string]);
            } else {
                // \Drupal::logger('gestion_documentaire')->notice('Aucune modification nécessaire pour les références.');
            }
        } else {
            // \Drupal::logger('gestion_documentaire')->notice('ref_js_data est null ou vide.');
            if (!empty($current_refs_string)) {
                $deleted_refs = $current_refs_array;
                $media->set('field_media_reference', '');
                $modif_ref = 1;
                // \Drupal::logger('gestion_documentaire')->notice('Suppression de toutes les références.');
            }
        }

        // -----------------------------
        // Gestion des langues
        // -----------------------------
        // \Drupal::logger('gestion_documentaire')->notice('=== DEBUT LANGUES ===');
        // \Drupal::logger('gestion_documentaire')->notice('lang_js_data brut: @data', ['@data' => $lang_js_data]);

        $current_langs_string = $media->get('field_media_lang')->value ?? '';
        $current_langs_array = array_filter(array_map('trim', explode(',', $current_langs_string)));
        // \Drupal::logger('gestion_documentaire')->notice('Langues actuelles: @langs', ['@langs' => json_encode($current_langs_array)]);

        if ($lang_js_data !== null && $lang_js_data !== '') {
            $lang_data = json_decode($lang_js_data, true);
            // \Drupal::logger('gestion_documentaire')->notice('json_last_error (langues): @error', ['@error' => json_last_error()]);

            if (json_last_error() === JSON_ERROR_NONE && is_array($lang_data)) {
                // \Drupal::logger('gestion_documentaire')->notice('Mode JSON (langues): @data', ['@data' => json_encode($lang_data)]);
                $lang_array = array_column($lang_data, 'langue');
                $lang_array = array_filter(array_map('trim', $lang_array));
            } else {
                // \Drupal::logger('gestion_documentaire')->notice('Mode CSV (langues): @data', ['@data' => $lang_js_data]);
                $lang_array = array_filter(array_map('trim', explode(',', $lang_js_data)));
            }

            $deleted_langs = array_diff($current_langs_array, $lang_array);
            $added_langs = array_diff($lang_array, $current_langs_array);
            // \Drupal::logger('gestion_documentaire')->notice('Langues supprimées: @deleted', ['@deleted' => json_encode($deleted_langs)]);
            // \Drupal::logger('gestion_documentaire')->notice('Langues ajoutées: @added', ['@added' => json_encode($added_langs)]);

            if ($lang_array !== $current_langs_array) {
                $new_string = empty($lang_array) ? '' : implode(',', $lang_array);
                $media->set('field_media_lang', $new_string);
                $modif_lang = 1;
                // \Drupal::logger('gestion_documentaire')->notice('Mise à jour des langues: @new', ['@new' => $new_string]);
            }
        } else {
            // \Drupal::logger('gestion_documentaire')->notice('lang_js_data est null ou vide.');
            if (!empty($current_langs_string)) {
                $deleted_langs = $current_langs_array;
                $media->set('field_media_lang', '');
                $modif_lang = 1;
                // \Drupal::logger('gestion_documentaire')->notice('Suppression de toutes les langues.');
            }
        }

        return [
            'modif_ref' => $ref_js_data,
            'modif_lang' => $lang_js_data,
            'deleted_langs' => $deleted_langs,
            'deleted_refs' => $deleted_refs,
            'added_langs' => $added_langs,
            'added_refs' => $added_refs,
        ];
    }
}
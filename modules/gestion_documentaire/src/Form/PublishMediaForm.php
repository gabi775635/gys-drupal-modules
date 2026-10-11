<?php

namespace Drupal\gestion_documentaire\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;

class PublishMediaForm extends FormBase {
    
    public function getFormId() {
        return 'gestion_documentaire_publish_media_form';
    }
    
    public function buildForm(array $form, FormStateInterface $form_state) {
        return $form;
    }
    
    public function submitForm(array &$form, FormStateInterface $form_state) {}
    
    /**
    * Construit le champ "Publier" à intégrer dans un autre formulaire.
    */
    public function buildPublishField(array &$form, bool $default_published = false): array {
        $form['publish_section'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Publication'),
            '#weight' => 1,
        ];
        
        $form['publish_section']['published'] = [
            '#type'          => 'checkbox',
            '#title'         => $this->t('Publish file'),
            '#default_value' => $default_published ? 1 : 0,
            '#description'   => $this->t('If checked, the media will be publicly visible.'),
        ];
        
        return $form;
    }
    
    /**
    * Applique le statut de publication et supprime les anciennes versions si nécessaire.
    *
    * @param \Drupal\media\Entity\Media $media
    *   Le média nouvellement créé ou mis à jour.
    * @param bool $publish
    *   True si le média doit être publié.
    * @param string $media_group_id
    *   L'identifiant du groupe de versions.
    * @param int $current_mid
    *   MID du média en cours (à exclure de la suppression).
    *
    * @return array
    *   ['deleted' => [...noms supprimés...], 'errors' => [...]]
    */
    public function applyPublishStatus(Media $media, bool $publish, string $media_group_id, int $current_mid): array {
        $deleted = [];
        $errors  = [];
        
        $was_published = (bool) $media->isPublished();
        
        if ($publish) {
            if (!$was_published) {
                $media->setPublished();
                \Drupal::messenger()->addStatus(t('Le média "@name" a été publié.', ['@name' => $media->getName()]));
                $media->setRevisionCreationTime(\Drupal::time()->getCurrentTime());
                $media->save();
            }
        } else {
            if ($was_published) {
                $media->setUnpublished();
                \Drupal::messenger()->addStatus(t('Le média "@name" a été dépublié.', ['@name' => $media->getName()]));
                $media->setRevisionCreationTime(\Drupal::time()->getCurrentTime());
                $media->save();
            }
        }
        
        
        // Si publié + group_id → supprimer les anciennes versions
        if ($publish && !empty($media_group_id)) {
            try {
                Database::setActiveConnection();
                $connection = Database::getConnection('drupal9');
                
                // Récupérer toutes les versions du groupe sauf le média courant
                $old_mids = $connection->query("
                SELECT DISTINCT m.mid, m.name
                FROM media_field_data m
                INNER JOIN media__field_media_group_id mrg ON m.mid = mrg.entity_id
                WHERE mrg.field_media_group_id_value = :group_id
                AND m.mid != :current_mid
                AND m.status = 1
                ", [
                    ':group_id'    => $media_group_id,
                    ':current_mid' => $current_mid,
                    ])->fetchAll();
                    
                    foreach ($old_mids as $row) {
                        $old_media = Media::load($row->mid);
                        if (!$old_media) {
                            continue;
                        }
                        
                        // Supprimer le fichier physique
                        $file = $old_media->get('field_media_document')->entity ?? null;
                        if ($file instanceof File) {
                            $file_system = \Drupal::service('file_system');
                            $realpath    = $file_system->realpath($file->getFileUri());
                            if ($realpath && file_exists($realpath)) {
                                try {
                                    unlink($realpath);
                                } catch (\Exception $e) {
                                    $errors[] = $this->t(
                                        'Impossible de supprimer le fichier physique "@name" : @error',
                                        ['@name' => $row->name, '@error' => $e->getMessage()]
                                    );
                                }
                            }
                            $file->delete();
                        }
                        
                        // Supprimer les entrées de rôle associées
                        $connection->delete('gestion_documentaire_role')
                        ->condition('id_char_version', 'media_' . $row->mid, 'LIKE')
                        ->execute();
                        
                        // Supprimer le média Drupal
                        $old_media->delete();
                        $deleted[] = $row->name;
                    }
                    
                } catch (\Exception $e) {
                    $errors[] = $this->t(
                        'Erreur lors de la suppression des anciennes versions : @error',
                        ['@error' => $e->getMessage()]
                    );
                }
            }
            
            return [
                'deleted' => $deleted,
                'errors'  => $errors,
            ];
        }
    }
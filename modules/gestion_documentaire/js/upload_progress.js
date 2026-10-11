(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.uploadProgress = {
    attach: function (context, settings) {
      
      // Intercepter la soumission du formulaire
      $('#gestion-documentaire-test-upload-form', context).once('upload-progress').on('submit', function(e) {
        e.preventDefault(); // ← AJOUT : Empêche la soumission automatique
        
        // Afficher le conteneur de progression
        $('#progress-wrapper').show();
        $('#submit-upload-btn').prop('disabled', true).val('Traitement en cours...');
        
        // Simuler la progression (en attendant les vraies données du backend)
        var fileInputs = $('input[name="documents[fids]"]').val();
        if (!fileInputs) {
          // Si pas de fichiers, soumettre quand même le formulaire
          this.submit();
          return;
        }

        var fileIds = fileInputs.split(',');
        var totalFiles = fileIds.length;
        var currentFile = 0;
        var form = this; // ← Sauvegarder la référence au formulaire


        


        
        function updateProgress() {
          currentFile++;
          var percentage = Math.round((currentFile / totalFiles) * 100);
          
          // ← IDs corrigés pour matcher le HTML PHP
          $('#progress-fill').css('width', percentage + '%').text(percentage + '%');
          $('#progress-log').append('<div>✓ Fichier ' + currentFile + ' / ' + totalFiles + ' traité</div>');
          $('#progress-log').scrollTop($('#progress-log')[0].scrollHeight);
        
          if (currentFile < totalFiles) {
            setTimeout(updateProgress, 2000);
          } else {
            $('#progress-fill').text('100%');
            $('#progress-log').append('<div><strong style="color: green;">✓ Traitement terminé !</strong></div>');
            
            setTimeout(function() {
              $(form).off('submit').submit();
            }, 1000);
          }
        }

        // Démarrer la simulation
        setTimeout(updateProgress, 500);
      });
    }
  };

})(jQuery, Drupal);
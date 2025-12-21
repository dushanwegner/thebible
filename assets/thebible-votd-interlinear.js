/**
 * TheBible VOTD Interlinear Widget JavaScript
 */
jQuery(document).ready(function($) {
    // Show/hide interlinear language options in widget admin
    $(document).on('change', '.thebible-votd-lang-mode', function() {
        if ($(this).val() === 'interlinear') {
            $(this).closest('form').find('.thebible-votd-interlinear-langs').show();
        } else {
            $(this).closest('form').find('.thebible-votd-interlinear-langs').hide();
        }
    });
    
    // Initialize on load
    $('.thebible-votd-lang-mode').each(function() {
        if ($(this).val() === 'interlinear') {
            $(this).closest('form').find('.thebible-votd-interlinear-langs').show();
        } else {
            $(this).closest('form').find('.thebible-votd-interlinear-langs').hide();
        }
    });
});

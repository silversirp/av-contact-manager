jQuery(document).ready(function($) {
    $('.av-toggle-msg').on('click', function() {
        $(this).siblings('.av-msg-content').slideToggle();
    });
});
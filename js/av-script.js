jQuery(document).ready(function($) {
    // 1. Initialize Datepicker
    $('.av_datepicker_input').datepicker({
        dateFormat: 'dd.mm.yy',
        firstDay: 1, // Monday
        monthNames: ['Jaanuar','Veebruar','Märts','Aprill','Mai','Juuni','Juuli','August','September','Oktoober','November','Detsember'],
        dayNamesMin: ['P','E','T','K','N','R','L']
    });

    // 2. Client-side Validation & Loading State
    $('#avContactForm').on('submit', function(e) {
        var isValid = true;
        var form = $(this);
        
        // Clear previous errors
        $('.av-msg').remove();
        form.find('input, textarea').css('border-color', '#ccc');

        // Check required fields
        form.find('[required]').each(function() {
            if( $.trim($(this).val()) === '' ) {
                isValid = false;
                $(this).css('border-color', '#cc0000');
            }
        });

        if ( ! isValid ) {
            e.preventDefault();
            form.prepend('<div class="av-msg error">Palun täitke kõik kohustuslikud väljad.</div>');
            $('html, body').animate({ scrollTop: form.offset().top - 100 }, 500);
            return;
        }

        // Show Loading State
        form.find('input[type="submit"]').prop('disabled', true);
        form.find('.av-loader').show();
    });
});
jQuery(document).ready(function($) {
    // 1. Initialize Datepicker
    // Check if element exists to prevent errors
    if( $('.av_datepicker_input').length ) {
        $('.av_datepicker_input').datepicker({
            dateFormat: 'dd.mm.yy',
            firstDay: 1, // Monday
            monthNames: ['Jaanuar','Veebruar','Märts','Aprill','Mai','Juuni','Juuli','August','September','Oktoober','November','Detsember'],
            dayNamesMin: ['P','E','T','K','N','R','L']
        });
    }

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
            e.preventDefault(); // Stop submission
            form.prepend('<div class="av-msg error">Palun täitke kõik kohustuslikud väljad.</div>');
            $('html, body').animate({ scrollTop: form.offset().top - 100 }, 500);
            return;
        }

        // Show Loading State
        var btn = form.find('input[type="submit"]');
        var loader = form.find('.av-loader');

        // We use a tiny timeout to ensure the form submission process starts 
        // before we disable the button, just to be safe across all browsers.
        setTimeout(function(){
            btn.val('Saatmine...');
            btn.prop('disabled', true);
            loader.show();
        }, 10);
    });
});
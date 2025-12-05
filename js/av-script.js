jQuery(document).ready(function($) {
    
    // 1. AUTO-SCROLL
    var resultMsg = $('#av-result-message');
    if ( resultMsg.length ) {
        $('html, body').animate({
            scrollTop: resultMsg.offset().top - 150
        }, 500);
    }

    // 2. Datepicker
    if( $('.av_datepicker_input').length ) {
        $('.av_datepicker_input').datepicker({
            dateFormat: 'dd.mm.yy',
            firstDay: 1, 
            constrainInput: false,
            monthNames: ['Jaanuar','Veebruar','Märts','Aprill','Mai','Juuni','Juuli','August','September','Oktoober','November','Detsember'],
            dayNamesMin: ['P','E','T','K','N','R','L']
        });
    }

    // 3. Vormi Validatsioon
    $('#avContactForm').on('submit', function(e) {
        var isValid = true;
        var form = $(this);
        var firstErrorField = null;
        
        $('.av-msg').remove();
        form.find('input, textarea').css('border-color', '#ccc');

        // A. Kohustuslikud väljad
        form.find('[required]').each(function() {
            if( $.trim($(this).val()) === '' ) {
                if( $(this).attr('type') === 'checkbox' && !$(this).is(':checked') ) {
                     isValid = false;
                     if( !firstErrorField ) firstErrorField = $(this);
                } else if ( $(this).attr('type') !== 'checkbox' ) {
                    isValid = false;
                    $(this).css('border-color', '#cc0000');
                    if( !firstErrorField ) firstErrorField = $(this);
                }
            }
        });

        // B. E-maili formaat
        var emailField = form.find('input[name="av_email"]');
        var emailVal = $.trim(emailField.val());
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if( emailVal !== '' && !emailPattern.test(emailVal) ) {
            isValid = false;
            emailField.css('border-color', '#cc0000');
            if( !firstErrorField ) firstErrorField = emailField;
            
            if (isValid) {
                form.prepend('<div id="av-result-message" class="av-msg error">Palun sisestage korrektne e-mail.</div>');
                $('html, body').animate({ scrollTop: form.offset().top - 100 }, 500);
                firstErrorField.focus();
                e.preventDefault();
                return;
            }
        }

        // Peata saatmine
        if ( ! isValid ) {
            e.preventDefault();
            form.prepend('<div id="av-result-message" class="av-msg error">Palun täitke kõik kohustuslikud väljad korrektselt.</div>');
            $('html, body').animate({ scrollTop: form.offset().top - 100 }, 500);
            if( firstErrorField ) firstErrorField.focus();
            return;
        }

        // C. Laadimisolek
        var btn = form.find('input[type="submit"]');
        var loader = form.find('.av-loader');

        setTimeout(function(){
            btn.val('Saatmine...');
            btn.prop('disabled', true);
            loader.show();
        }, 10);
    });
});
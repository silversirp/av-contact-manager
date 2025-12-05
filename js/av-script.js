jQuery(document).ready(function($) {
    
    // 1. AUTO-SCROLL (Handles PRG Redirect Message)
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

    // 3. Character Counter
    $('textarea[name="av_desc"]').on('input', function() {
        var len = $(this).val().length;
        var max = $(this).attr('maxlength');
        var counter = $('.char-counter');
        
        counter.text( len + ' / ' + max );
        
        if ( len > max * 0.9 ) {
            counter.css('color', '#cc0000');
        } else {
            counter.css('color', '#666');
        }
    });

    // 4. Form Validation
    $('#avContactForm').on('submit', function(e) {
        var isValid = true;
        var form = $(this);
        var firstErrorField = null;
        
        $('.av-msg').remove();
        form.find('input, textarea').css('border-color', '#ccc');

        // A. Required Fields
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

        // B. Email Format
        var emailField = form.find('input[name="av_email"]');
        var emailVal = $.trim(emailField.val());
        var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        if( emailVal !== '' && !emailPattern.test(emailVal) ) {
            isValid = false;
            emailField.css('border-color', '#cc0000');
            if( !firstErrorField ) firstErrorField = emailField;
        }

        // Stop if invalid
        if ( ! isValid ) {
            e.preventDefault();
            form.prepend('<div id="av-result-message" class="av-msg error">Palun täitke kõik kohustuslikud väljad korrektselt.</div>');
            $('html, body').animate({ scrollTop: form.offset().top - 100 }, 500);
            if( firstErrorField ) firstErrorField.focus();
            return;
        }

        // C. Loading State
        var btn = form.find('input[type="submit"]');
        var loader = form.find('.av-loader');

        btn.addClass('submitting');
        btn.val('Saatmine...');
        btn.prop('disabled', true);
        loader.css('display', 'inline-block'); // Show spinner
    });
});
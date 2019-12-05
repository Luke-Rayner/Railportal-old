(function( $ ) {
    $.fn.flashAlerts = function() {
        var field = $(this);
        var url = site['uri']['public'] + "/alerts";
        return $.getJSON( url, {})
        .then(function( data ) {        // Pass the deferral back
            // Display alerts
            var alertHTML = "";
            if (data) {
                jQuery.each(data, function(alert_idx, alert_message) {
                    if (alert_message['type'] == "success"){
                        alertHTML += "<div class='alert alert-success'>" + alert_message['message'] + "</div>";
                    } else if (alert_message['type'] == "warning"){
                        alertHTML += "<div class='alert alert-warning'>" + alert_message['message'] + "</div>";
                    } else 	if (alert_message['type'] == "info"){
                        alertHTML += "<div class='alert alert-info'>" + alert_message['message'] + "</div>";
                    } else if (alert_message['type'] == "danger"){
                        alertHTML += "<div class='alert alert-danger'>" + alert_message['message'] + "</div>";
                    }
                });
            }
            field.html(alertHTML);
            $("html, body").animate({ scrollTop: 0 }, "fast");		// Scroll back to top of page
            
            return data;
        });
    };
}( jQuery ));

// Set jQuery.validate settings for bootstrap integration
jQuery.validator.setDefaults({
    highlight: function(element) {
        jQuery(element).closest('.form-group').addClass('has-error');
        jQuery(element).closest('.form-group').removeClass('has-success has-feedback');
        jQuery(element).closest('.form-group').find('.form-control-feedback').remove();
    },
    unhighlight: function(element) {
        jQuery(element).closest('.form-group').removeClass('has-error');
    },
    errorElement: 'span',
    errorClass: 'text-danger',
    errorPlacement: function(error, element) {
        if(element.parent('.input-group').length) {
            error.insertAfter(element.parent());
        } else {
            error.insertAfter(element);
        }
    },
    success: function(element) {
        jQuery(element).closest('.form-group').addClass('has-success has-feedback');
        jQuery(element).after('<i class="fa fa-check form-control-feedback" aria-hidden="true"></i>');
    }
});

// improve email validation with custom regex
// original regex: /[a-z]+@[a-z]+\.[a-z]+/
jQuery.validator.methods.email = function(value, element) {
    return this.optional(element) || /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/.test(value);
}

// Process a UserFrosting form, displaying messages from the message stream and executing specified callbacks
function ufFormSubmit(formElement, validators, msgElement, successCallback, msgCallback, beforeSubmitCallback) {
    formElement.validate({
        rules:          validators['rules'],
        messages :      validators['messages'],
        submitHandler:  function (f, event) {
            // Execute any "before submit" callback
            if (typeof beforeSubmitCallback !== "undefined")
                beforeSubmitCallback();        
            var form = $(f);
            // Set "loading" text for submit button, if it exists, and disable button
            var submit_button = form.find("button[type=submit]");
            if (submit_button) {
                var submit_button_text = submit_button.html();
                submit_button.prop( "disabled", true );
                submit_button.html("<i class='fa fa-spinner fa-spin'></i>"); 
            }
            // Serialize and post to the backend script in ajax mode
            var serializedData = form.find('input, textarea, select').not(':checkbox').serialize();
            // Get unchecked checkbox values, set them to 0
            form.find('input[type=checkbox]:enabled').each(function() {
                if ($(this).is(':checked'))
                    serializedData += "&" + encodeURIComponent(this.name) + "=1";
                else
                    serializedData += "&" + encodeURIComponent(this.name) + "=0";
            });        
            
            // Append page CSRF token
            var csrf_token = $("meta[name=csrf_token]").attr("content");
            serializedData += "&csrf_token=" + encodeURIComponent(csrf_token);            
            
            var url = form.attr('action');
            $.ajax({  
              type: "POST",  
              url: url,  
              data: serializedData       
            })
            .done(successCallback)
            .fail(function(jqXHR) {
                if ((typeof site !== "undefined") && site['debug'] == true && jqXHR.status == "500") {
                    document.body.innerHTML = jqXHR.responseText;
                } else {
                    console.log("Error (" + jqXHR.status + "): " + jqXHR.responseText );
                    // Display errors on failure
                    msgElement.flashAlerts().done(function() {
                        // Do any additional callbacks here after displaying messages
                        if (typeof msgCallback !== "undefined")
                            msgCallback();
                    });
                }
            }).always(function () {
                // Restore button text and re-enable submit button
                if (submit_button) {
                    submit_button.prop( "disabled", false );
                    submit_button.html(submit_button_text);
                }
            });
        }
    });
}
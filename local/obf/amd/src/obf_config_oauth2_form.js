define(['jquery'], function($) {
    return {
        init: function() {
            $('#id_add_rules_button').on('click', function() {
                $('input[name="add_rules_value"]').val(true);
                this.form.submit();
            });

            $('.delete-button').on('click', function(event) {
                event.preventDefault();
                $('input[name="delete_rule"]').val(true);
                $('input[name="delete_rule_id"]').val($(this).attr('ruleid'));
                this.form.submit();
            });
        }
    };
});

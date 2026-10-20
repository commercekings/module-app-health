/**
 * An App health button ("Send test alert", "Send status now"): posts to its URL with the form key and shows the answer
 * under the button.
 */
define(['jquery', 'mage/translate'], function ($) {
    'use strict';

    return function (config, element) {
        var $root = $(element),
            $button = $root.find('[data-role=send]'),
            $result = $root.find('[data-role=result]');

        $button.on('click', function () {
            $button.prop('disabled', true);
            $result.text('');
            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {form_key: window.FORM_KEY}
            }).done(function (response) {
                $result.text(response.message || '');
            }).fail(function () {
                $result.text(config.failed || $.mage.__('Not sent. Try again.'));
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    };
});

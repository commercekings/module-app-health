/**
 * "Send test alert": posts the website of the configuration scope and shows the answer under the button.
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
                data: {website: config.website, form_key: window.FORM_KEY}
            }).done(function (response) {
                $result.text(response.message || '');
            }).fail(function () {
                $result.text($.mage.__('The test alert could not be sent. Try again.'));
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    };
});

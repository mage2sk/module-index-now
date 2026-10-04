define([
    "jquery",
    "mage/translate"
], function ($) {
    "use strict";

    return function (widget) {
        $.validator.addMethod(
            "validate-indexnow-key",
            function (value) {
                var key = $.trim(value || "");

                return key === "" || /^[a-zA-Z0-9-]{8,128}$/.test(key);
            },
            $.mage.__("The IndexNow API key must be 8 to 128 characters long and contain only letters, digits and dashes.")
        );

        return widget;
    };
});

/**
 * View as Role — Admin bar role switching.
 *
 * @package WPTransformed
 */
(function () {
    'use strict';

    var data = window.wptViewAsRoleData || {};

    function sendAction(action, extraFields, errorMsg) {
        if (!data.ajaxUrl) return;

        var formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', data.nonce);

        if (extraFields) {
            Object.keys(extraFields).forEach(function (key) {
                formData.append(key, extraFields[key]);
            });
        }

        fetch(data.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (result.success) {
                    window.location.reload();
                } else {
                    alert(result.data && result.data.message ? result.data.message : errorMsg);
                }
            })
            .catch(function () {
                alert('Network error. Please try again.');
            });
    }

    window.wptViewAsRole = {
        switchTo: function (role) {
            if (!role) return;
            sendAction('wpt_switch_role', { role: role }, 'Failed to switch role.');
        },

        switchBack: function () {
            sendAction('wpt_switch_back', null, 'Failed to switch back.');
        },
    };
})();

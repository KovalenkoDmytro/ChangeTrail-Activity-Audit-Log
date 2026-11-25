/* ChangeTrail: confirmation prompts for destructive settings. No other behaviour. */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-kac-confirm]');
        if (button && button.tagName !== 'SELECT' && !window.confirm(button.getAttribute('data-kac-confirm'))) {
            event.preventDefault();
        }
    });

    var form = document.getElementById('kac-settings-form');
    var owner = document.getElementById('kac-owner');
    if (form && owner) {
        form.addEventListener('submit', function (event) {
            if (owner.value !== owner.getAttribute('data-kac-current') && !window.confirm(owner.getAttribute('data-kac-confirm'))) {
                event.preventDefault();
            }
        });
    }
})();

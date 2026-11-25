/* ChangeTrail: sends a small device fingerprint once per browser session so shared accounts can be told apart. */
(function () {
    'use strict';

    var config = window.kovalenkoAdminChangeAuditDevice;
    if (!config || !window.fetch || !window.sessionStorage) {
        return;
    }

    var key = 'kovalenkoAdminChangeAuditDevice';
    try {
        if (window.sessionStorage.getItem(key) === config.session) {
            return;
        }
    } catch (e) {
        return;
    }

    function webgl() {
        try {
            var canvas = document.createElement('canvas');
            var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            if (!gl) {
                return '';
            }
            var info = gl.getExtension('WEBGL_debug_renderer_info');
            return String(gl.getParameter(info ? info.UNMASKED_RENDERER_WEBGL : gl.RENDERER) || '');
        } catch (e) {
            return '';
        }
    }

    var nav = window.navigator;
    var data = {
        screen: window.screen.width + 'x' + window.screen.height,
        pixelRatio: String(window.devicePixelRatio || 1),
        colorDepth: String(window.screen.colorDepth || ''),
        timezone: (window.Intl && Intl.DateTimeFormat().resolvedOptions().timeZone) || '',
        language: nav.language || '',
        languages: (nav.languages || []).slice(0, 5).join(','),
        cores: String(nav.hardwareConcurrency || ''),
        memory: String(nav.deviceMemory || ''),
        webgl: webgl(),
        touch: String(nav.maxTouchPoints || 0),
        platform: (nav.userAgentData && nav.userAgentData.platform) || nav.platform || ''
    };

    var body = new window.FormData();
    body.append('action', config.action);
    body.append('nonce', config.nonce);
    body.append('fp', JSON.stringify(data));

    window.fetch(config.url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
        if (response.ok) {
            window.sessionStorage.setItem(key, config.session);
        }
    }).catch(function () {});
})();

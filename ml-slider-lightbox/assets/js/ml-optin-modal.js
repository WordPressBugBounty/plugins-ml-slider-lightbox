(function () {
    'use strict';

    var settings = window.mlLightboxOptin || {};
    var strings = settings.strings || {};

    document.addEventListener('DOMContentLoaded', function () {
        var overlay = document.getElementById('ml-optin-modal');

        if (!overlay) {
            return;
        }

        var dialog = overlay.querySelector('.ml-optin-dialog');
        var title = overlay.querySelector('.ml-optin-title');
        var email = overlay.querySelector('.ml-optin-email');
        var submit = overlay.querySelector('.ml-optin-submit');
        var decline = overlay.querySelector('.ml-optin-decline');
        var finish = overlay.querySelector('.ml-optin-finish');
        var report = overlay.querySelector('.ml-optin-report');
        var lastFocused = document.activeElement;
        var state = 'idle';

        function isValidEmail(value) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((value || '').trim());
        }

        function panel(name) {
            return overlay.querySelector('[data-panel="' + name + '"]');
        }

        function setState(next) {
            state = next;
            dialog.setAttribute('data-state', next);

            var visible = 'sending' === next ? 'idle' : next;

            ['idle', 'error', 'sent'].forEach(function (name) {
                panel(name).hidden = name !== visible;
            });

            Array.prototype.forEach.call(submit.querySelectorAll('[data-label]'), function (label) {
                label.hidden = label.getAttribute('data-label') !== next;
            });

            title.textContent = dialog.getAttribute('data-title-' + visible) || title.textContent;

            var done = 'sent' === next;
            submit.hidden = done;
            decline.hidden = done;
            finish.hidden = !done;

            syncSubmit();
        }

        function syncSubmit() {
            submit.disabled = 'sending' === state || !isValidEmail(email.value);
        }

        function close() {
            overlay.hidden = true;
            document.removeEventListener('keydown', onKeydown, true);

            if (lastFocused && lastFocused.focus) {
                lastFocused.focus();
            }
        }

        function post(body, onDone) {
            var request = new XMLHttpRequest();

            request.open('POST', settings.ajaxUrl, true);
            request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            request.onload = function () {
                var payload = null;

                try {
                    payload = JSON.parse(request.responseText);
                } catch (error) {
                    payload = null;
                }

                onDone(payload);
            };
            request.onerror = function () {
                onDone(null);
            };
            request.send(body);
        }

        function encode(fields) {
            return Object.keys(fields).map(function (key) {
                return encodeURIComponent(key) + '=' + encodeURIComponent(fields[key]);
            }).join('&');
        }

        function showReport(data) {
            if (!data || !data.report) {
                return;
            }

            var ok = ['subscribed', 'duplicate'].indexOf(data.status) > -1;

            report.hidden = false;
            report.className = 'ml-optin-report ' + (ok ? 'is-ok' : 'is-bad');
            report.querySelector('.ml-optin-report-status').textContent = '(' + data.status + ')';

            try {
                report.querySelector('.ml-optin-report-body').textContent = JSON.stringify(data.report, null, 2);
            } catch (error) {
                report.querySelector('.ml-optin-report-body').textContent = String(data.report);
            }
        }

        function fail(message) {
            panel('error').querySelector('.ml-optin-error-message').textContent =
                message || strings.genericError || '';
            setState('error');
            email.focus();
        }

        function subscribe() {
            setState('sending');
            report.hidden = true;

            post(encode({
                action: 'ml_lightbox_optin',
                nonce: settings.nonce,
                choice: 'yes',
                email: email.value.trim()
            }), function (payload) {
                if (!payload) {
                    fail(strings.saveError);
                    return;
                }

                // Errors carry the reason the server rejected the address,
                // which happens before anything reaches the connect service.
                if (!payload.success) {
                    fail(payload.data && payload.data.message ? payload.data.message : strings.saveError);
                    return;
                }

                var data = payload.data || {};

                showReport(data);

                // 'duplicate' means the address was handed off previously, so
                // there is nothing to send and nothing to apologise for.
                if (['subscribed', 'duplicate'].indexOf(data.status) > -1) {
                    overlay.querySelector('.ml-optin-address').textContent = data.email || email.value.trim();
                    setState('sent');
                    finish.focus();
                    return;
                }

                fail(strings.genericError);
            });
        }

        function dismiss() {
            if ('sending' === state) {
                return;
            }

            if ('sent' === state) {
                close();
                return;
            }

            close();

            post(encode({
                action: 'ml_lightbox_optin',
                nonce: settings.nonce,
                choice: 'no'
            }), function () {});
        }

        function onKeydown(event) {
            if ('Escape' === event.key) {
                dismiss();
                return;
            }

            if ('Tab' !== event.key) {
                return;
            }

            var focusable = Array.prototype.filter.call(
                dialog.querySelectorAll('input, button, a[href], summary'),
                function (node) {
                    return !node.disabled && null !== node.offsetParent;
                }
            );

            if (!focusable.length) {
                return;
            }

            var first = focusable[0];
            var last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }

        email.addEventListener('input', syncSubmit);
        email.addEventListener('keydown', function (event) {
            if ('Enter' === event.key && !submit.disabled) {
                event.preventDefault();
                subscribe();
            }
        });
        submit.addEventListener('click', subscribe);
        decline.addEventListener('click', dismiss);
        finish.addEventListener('click', close);
        overlay.addEventListener('mousedown', function (event) {
            if (event.target === overlay) {
                dismiss();
            }
        });
        document.addEventListener('keydown', onKeydown, true);

        overlay.hidden = false;
        setState('idle');
        email.focus();
    });
}());

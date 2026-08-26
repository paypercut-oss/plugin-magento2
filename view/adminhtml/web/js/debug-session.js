/**
 * Debug (telemetry) session panel.
 *
 * Owns three things: the consent modal, the live countdown, and a poll that
 * doubles as the delivery trigger while this screen is open.
 */
define([], function () {
    'use strict';

    return function (config, element) {
        var panel = element || document.getElementById('paypercut-debug-session');

        if (!panel || !config) {
            return;
        }

        var modal = document.getElementById('paypercut-debug-session-modal');
        var statusBox = document.getElementById('paypercut-debug-session-status');
        var i18n = config.i18n || {};

        var pollTimer = null;
        var tickTimer = null;
        // Guards against the deadline tick firing a request every second.
        var polling = false;
        var deadlineConfirmed = false;
        var pollFailures = 0;
        var state = config.initial || { state: 'idle' };
        // Offset between this browser's clock and the server's, so a wrong
        // local clock cannot make the countdown disagree with when the session
        // actually ends.
        var clockOffset = (config.now || 0) - Math.floor(Date.now() / 1000);

        function serverNow() {
            return Math.floor(Date.now() / 1000) + clockOffset;
        }

        function show(message, kind) {
            if (!statusBox) {
                return;
            }

            statusBox.textContent = message;
            statusBox.className = 'message ' + (kind === 'error' ? 'message-error' : 'message-success');
            statusBox.style.display = '';
        }

        function hideStatus() {
            if (statusBox) {
                statusBox.style.display = 'none';
            }
        }

        function fill(selector, value) {
            var nodes = panel.querySelectorAll(selector);

            for (var i = 0; i < nodes.length; i++) {
                nodes[i].textContent = value;
            }
        }

        function formatClock(unixSeconds) {
            var date = new Date(unixSeconds * 1000);
            var hours = date.getHours();
            var minutes = date.getMinutes();

            return (hours < 10 ? '0' + hours : String(hours)) + ':' + (minutes < 10 ? '0' + minutes : String(minutes));
        }

        function formatRemaining(seconds) {
            if (seconds < 0) {
                seconds = 0;
            }

            var minutes = Math.floor(seconds / 60);
            var rest = seconds % 60;

            return minutes + ':' + (rest < 10 ? '0' + rest : String(rest));
        }

        function render() {
            var blocks = panel.querySelectorAll('[data-paypercut-state]');

            for (var i = 0; i < blocks.length; i++) {
                blocks[i].hidden = blocks[i].getAttribute('data-paypercut-state') !== state.state;
            }

            fill('[data-paypercut-session-id]', state.session_id || '');
            fill('[data-paypercut-started-by]', state.started_by_name || '');
            fill('[data-paypercut-sent]', String(state.events_sent || 0));
            fill('[data-paypercut-dropped]', String(state.events_dropped || 0));
            fill('[data-paypercut-ends-at]', state.expires_at ? formatClock(state.expires_at) : '');

            if (state.state === 'failed') {
                // The failed block carries this message itself. Leaving the
                // transient status box up as well prints the same error twice.
                hideStatus();
                fill('[data-paypercut-failed-message]', state.message || '');
                fill('[data-paypercut-trace-id]', state.trace_id || '');

                var reference = panel.querySelector('[data-paypercut-reference]');

                if (reference) {
                    reference.hidden = !state.trace_id;
                }
            }

            tick();
        }

        function tick() {
            if (state.state !== 'running') {
                return;
            }

            var remaining = (state.expires_at || 0) - serverNow();

            fill('[data-paypercut-countdown]', formatRemaining(remaining));

            // Ask the server to confirm and tear down — once, not once a second.
            if (remaining <= 0 && !deadlineConfirmed) {
                deadlineConfirmed = true;
                poll();
            }
        }

        function dropSentLog() {
            var log = panel.querySelector('[data-paypercut-log]');

            if (log) {
                log.parentNode.removeChild(log);
            }
        }

        function apply(next) {
            var ended = state.state === 'running' && next.state !== 'running';
            var started = state.state !== 'running' && next.state === 'running';

            state = next;
            pollFailures = 0;

            if (started) {
                deadlineConfirmed = false;
                // Starting clears the log server-side. This block is rendered
                // once per page load, so without this the merchant expands it
                // and reads the previous session's events under the new
                // session's heading.
                dropSentLog();
            }

            if (typeof next.now === 'number') {
                clockOffset = next.now - Math.floor(Date.now() / 1000);
            }

            render();

            if (next.state !== 'running') {
                stopTicking();
            } else {
                startTicking();
            }

            if (ended) {
                show(i18n.sessionEnded || '', 'success');
            }
        }

        function request(url, onDone) {
            var params = new URLSearchParams();
            params.append('form_key', config.formKey);

            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    Accept: 'application/json'
                },
                body: params.toString()
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    onDone(data, null);
                })
                .catch(function (error) {
                    // A host WAF answering the admin endpoint with HTML lands here.
                    onDone(null, error);
                });
        }

        function poll() {
            if (polling) {
                return;
            }

            polling = true;

            request(config.statusUrl, function (data, error) {
                polling = false;

                if (error) {
                    pollFailures++;

                    // A blip should not freeze the panel for good, but a host
                    // that keeps answering with HTML should not be polled
                    // forever either.
                    if (pollFailures >= 3) {
                        stopPolling();
                        stopTicking();
                        show(i18n.adminUnreachable || '', 'error');
                        return;
                    }

                    schedulePoll(pollFailures * 30000);
                    return;
                }

                if (data && data.success && data.data) {
                    apply(data.data);
                }

                schedulePoll();
            });
        }

        function schedulePoll(overrideMs) {
            stopPolling();

            if (state.state !== 'running' || document.hidden) {
                return;
            }

            var base = (config.pollSeconds || 60) * 1000;
            // ±20% jitter so many open dashboards do not land together.
            var delay = overrideMs || base * (0.8 + Math.random() * 0.4);

            pollTimer = window.setTimeout(poll, delay);
        }

        function stopPolling() {
            if (pollTimer) {
                window.clearTimeout(pollTimer);
                pollTimer = null;
            }
        }

        function startTicking() {
            if (!tickTimer) {
                tickTimer = window.setInterval(tick, 1000);
            }
        }

        function stopTicking() {
            if (tickTimer) {
                window.clearInterval(tickTimer);
                tickTimer = null;
            }
        }

        function openModal() {
            if (modal) {
                modal.hidden = false;
            }
        }

        function closeModal() {
            if (modal) {
                modal.hidden = true;
            }
        }

        function copySessionId(button) {
            // Two rows offer a Copy button — the session id and the support
            // reference — so copy the code beside this one, not the first on
            // screen.
            var row = button.closest('p');
            var node = (row && row.querySelector('code')) || panel.querySelector('[data-paypercut-session-id]');
            var value = node ? node.textContent : '';

            if (!value) {
                return;
            }

            var confirmCopy = function () {
                var label = button.querySelector('span') || button;
                var original = label.textContent;

                label.textContent = i18n.copied || '';
                window.setTimeout(function () {
                    label.textContent = original;
                }, 2000);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(confirmCopy, function () {});
                return;
            }

            var field = document.createElement('textarea');
            field.value = value;
            document.body.appendChild(field);
            field.select();

            try {
                document.execCommand('copy');
                confirmCopy();
            } catch (error) {
                // Copying is a convenience; the id is on screen either way.
            }

            document.body.removeChild(field);
        }

        panel.addEventListener('click', function (event) {
            var startButton = event.target.closest('.paypercut-debug-session__start');

            if (startButton) {
                event.preventDefault();
                hideStatus();
                openModal();
                return;
            }

            var stopButton = event.target.closest('.paypercut-debug-session__stop');

            if (stopButton) {
                event.preventDefault();

                var stopLabel = stopButton.querySelector('span') || stopButton;

                stopButton.disabled = true;
                stopLabel.textContent = i18n.stopping || '';

                request(config.stopUrl, function (data, error) {
                    stopButton.disabled = false;
                    stopLabel.textContent = i18n.stopNow || '';

                    if (error) {
                        show(i18n.networkError || '', 'error');
                        return;
                    }

                    if (data && data.success && data.data) {
                        apply(data.data);
                    } else if (data && data.data && data.data.message) {
                        show(data.data.message, 'error');
                    }
                });

                return;
            }

            var copyButton = event.target.closest('[data-paypercut-copy]');

            if (copyButton) {
                event.preventDefault();
                copySessionId(copyButton);
            }
        });

        if (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target.hasAttribute('data-paypercut-close-debug-session-modal')) {
                    event.preventDefault();
                    closeModal();
                    return;
                }

                var confirmButton = event.target.closest('.paypercut-debug-session-modal__confirm');

                if (!confirmButton) {
                    return;
                }

                event.preventDefault();

                var confirmLabel = confirmButton.querySelector('span') || confirmButton;

                confirmButton.disabled = true;
                confirmLabel.textContent = i18n.starting || '';

                request(config.startUrl, function (data, error) {
                    confirmButton.disabled = false;
                    confirmLabel.textContent = i18n.startSession || '';
                    closeModal();

                    if (error) {
                        show(i18n.networkError || '', 'error');
                        return;
                    }

                    if (data && data.success && data.data) {
                        apply(data.data);
                        schedulePoll();
                        return;
                    }

                    if (data && data.data && data.data.message) {
                        show(data.data.message, 'error');
                    }

                    // A rejected start writes a `failed` record, so re-read it.
                    poll();
                });
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                stopPolling();
                return;
            }

            if (state.state === 'running') {
                poll();
            }
        });

        render();
        schedulePoll();

        if (state.state === 'running') {
            startTicking();
        }

        window.addEventListener('beforeunload', function () {
            stopPolling();
            stopTicking();
        });
    };
});

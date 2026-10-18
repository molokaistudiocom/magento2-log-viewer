/**
 * Log file viewer: the tail of the file, older pages on demand, live follow and search.
 *
 * Log lines are untrusted text (request URLs, API payloads, exception messages), so they only ever reach
 * the page as text nodes - never through .html() or innerHTML.
 *
 * Positions are the byte offsets the server returns. They must not be recomputed from the displayed text:
 * a JavaScript string length counts UTF-16 units, not bytes.
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    var FOLLOW_INTERVAL_MS = 3000;

    function formatSize(bytes) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB'],
            unit = 0;

        while (bytes >= 1024 && unit < units.length - 1) {
            bytes /= 1024;
            unit++;
        }

        return (unit === 0 ? bytes : bytes.toFixed(1)) + ' ' + units[unit];
    }

    return function (config, element) {
        var $root = $(element),
            $output = $root.find('[data-role=output]'),
            $status = $root.find('[data-role=status]'),
            $older = $root.find('[data-role=older]'),
            $earlier = $root.find('[data-role=earlier-matches]'),
            $follow = $root.find('[data-role=follow]'),
            $query = $root.find('[data-role=query]'),
            $clearSearch = $root.find('[data-role=clear-search]'),
            output = $output.get(0),
            oldestStart = 0,
            newestEnd = 0,
            fileSize = 0,
            followTimer = null,
            searching = false,
            lastQuery = '',
            earliestMatchLine = null,
            busy = false;

        function setStatus(text) {
            $status.text(text);
        }

        function showError(xhr) {
            var error = xhr.responseJSON && xhr.responseJSON.error;

            setStatus(error || $t('The request failed. Reload the page and try again.'));
        }

        function request(url, data) {
            // The admin's jQuery setup turns an untyped call into a POST, which the GET-only actions reject with a 404.
            return $.ajax({url: url, type: 'GET', data: $.extend({file: config.file}, data), dataType: 'json'});
        }

        function isScrolledToBottom() {
            return output.scrollHeight - output.scrollTop - output.clientHeight < 50;
        }

        function scrollToBottom() {
            output.scrollTop = output.scrollHeight;
        }

        function updateLogStatus() {
            $older.prop('hidden', oldestStart === 0);
            setStatus($t('Showing bytes %1 to %2 of %3.')
                .replace('%1', formatSize(oldestStart))
                .replace('%2', formatSize(newestEnd))
                .replace('%3', formatSize(fileSize)) +
                (oldestStart === 0 ? ' ' + $t('This is the start of the file.') : ''));
        }

        function loadTail() {
            busy = true;
            setStatus($t('Loading...'));

            return request(config.chunkUrl, {}).done(function (chunk) {
                $output.empty().append(document.createTextNode(chunk.content));
                oldestStart = chunk.start;
                newestEnd = chunk.end;
                fileSize = chunk.fileSize;
                updateLogStatus();
                scrollToBottom();
            }).fail(showError).always(function () {
                busy = false;
            });
        }

        function loadOlder() {
            if (busy || oldestStart === 0) {
                return;
            }
            busy = true;

            request(config.chunkUrl, {before: oldestStart}).done(function (chunk) {
                var heightBefore = output.scrollHeight;

                $output.prepend(document.createTextNode(chunk.content));
                // Keep the line the user was reading where it was.
                output.scrollTop += output.scrollHeight - heightBefore;
                oldestStart = chunk.start;
                fileSize = chunk.fileSize;
                updateLogStatus();
            }).fail(showError).always(function () {
                busy = false;
            });
        }

        function poll() {
            if (busy || searching) {
                return;
            }
            busy = true;

            request(config.chunkUrl, {after: newestEnd}).done(function (chunk) {
                var stickToBottom = isScrolledToBottom();

                if (chunk.truncated) {
                    // The file was rotated or emptied: what is on screen no longer exists.
                    $output.empty();
                    oldestStart = chunk.start;
                }
                if (chunk.content !== '') {
                    $output.append(document.createTextNode(chunk.content));
                }
                newestEnd = chunk.end;
                fileSize = chunk.fileSize;
                updateLogStatus();
                if (stickToBottom) {
                    scrollToBottom();
                }
            }).fail(function (xhr) {
                stopFollowing();
                showError(xhr);
            }).always(function () {
                busy = false;
            });
        }

        function startFollowing() {
            followTimer = window.setInterval(poll, FOLLOW_INTERVAL_MS);
            $follow.text($t('Stop following'));
            poll();
        }

        function stopFollowing() {
            window.clearInterval(followTimer);
            followTimer = null;
            $follow.text($t('Follow'));
        }

        function search(beforeLine) {
            var data = {q: lastQuery};

            if (beforeLine) {
                data.before_line = beforeLine;
            }
            busy = true;
            setStatus($t('Searching...'));

            request(config.searchUrl, data).done(function (result) {
                var lines = $.map(result.matches, function (match) {
                    return match.line + ': ' + match.text;
                });

                if (!beforeLine) {
                    $output.empty();
                }
                $output.prepend(document.createTextNode(lines.length ? lines.join('\n') + '\n' : ''));
                earliestMatchLine = result.matches.length ? result.matches[0].line : null;
                $earlier.prop('hidden', !result.hasEarlier);
                setStatus(result.total === 0 && !beforeLine
                    ? $t('No lines contain "%1".').replace('%1', lastQuery)
                    : $t('%1 matching lines, showing the last %2.')
                        .replace('%1', result.total)
                        .replace('%2', result.matches.length));
                if (!beforeLine) {
                    scrollToBottom();
                }
            }).fail(showError).always(function () {
                busy = false;
            });
        }

        $older.on('click', loadOlder);

        $follow.on('click', function () {
            if (followTimer) {
                stopFollowing();
            } else {
                startFollowing();
            }
        });

        $root.find('[data-role=wrap]').on('change', function () {
            $output.css('white-space', this.checked ? 'pre-wrap' : 'pre');
        });

        $root.find('[data-role=search-form]').on('submit', function (event) {
            event.preventDefault();
            lastQuery = $.trim($query.val());
            if (lastQuery.length < 2 || busy) {
                return;
            }
            searching = true;
            $older.prop('hidden', true);
            $clearSearch.prop('hidden', false);
            search(null);
        });

        $earlier.on('click', function () {
            if (!busy && earliestMatchLine) {
                search(earliestMatchLine);
            }
        });

        $clearSearch.on('click', function () {
            if (busy) {
                return;
            }
            searching = false;
            $query.val('');
            $clearSearch.prop('hidden', true);
            $earlier.prop('hidden', true);
            loadTail();
        });

        loadTail();
    };
});

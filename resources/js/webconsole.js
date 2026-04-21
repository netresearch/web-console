/**
 * Web-Console terminal bootstrapper.
 *
 * Targets jquery.terminal 2.x running on top of jQuery 3.x. Talks to the
 * PHP backend via plain JSON-RPC 2.0 over XHR ($.ajax) -- the historical
 * $.jrpc plugin is gone in jquery.terminal 2.x, so we hand-roll the
 * envelope here.
 */
(function ($) {
    $(document).ready(function () {
        var settings = {
            url: 'webconsole.php',
            prompt_path_length: 32,
            domain: document.domain || window.location.host,
            is_small_window: $(document).width() < 625
        };
        var environment = { user: '', hostname: '', path: '' };
        var no_login = typeof __NO_LOGIN__ !== 'undefined' ? __NO_LOGIN__ : false;
        var silent_mode = false;
        var rpc_id = 0;

        // Banner
        var banner_main = 'Web Console';
        var banner_link = 'https://github.com/netresearch/web-console';
        var banner_extra = banner_link + '\n';
        if (!settings.is_small_window) {
            banner_main =
                '  _    _      _     _____                       _                ' +
                '\n | |  | |    | |   /  __ \\                     | |            ' +
                '\n | |  | | ___| |__ | /  \\/ ___  _ __  ___  ___ | | ___        ' +
                '\n | |/\\| |/ _ \\ \'_ \\| |    / _ \\| \'_ \\/ __|/ _ \\| |/ _ \\ ' +
                '\n \\  /\\  /  __/ |_) | \\__/\\ (_) | | | \\__ \\ (_) | |  __/  ' +
                '\n  \\/  \\/ \\___|____/ \\____/\\___/|_| |_|___/\\___/|_|\\___| ';
            banner_extra = '\n                 ' + banner_link + '\n';
        }

        // JSON-RPC call helper. Returns nothing; the callbacks handle
        // success/error. `options.pause = false` keeps the terminal
        // responsive (used for tab completion).
        function rpc(terminal, method, params, onSuccess, onError, options) {
            options = $.extend({ pause: true }, options);
            if (options.pause) {
                terminal.pause();
            }

            var payload = {
                jsonrpc: '2.0',
                method: method,
                params: params || [],
                id: ++rpc_id
            };

            $.ajax({
                url: settings.url,
                type: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify(payload)
            })
                .done(function (json) {
                    if (options.pause) {
                        terminal.resume();
                    }

                    if (json && !json.error) {
                        if (onSuccess) {
                            onSuccess(json.result);
                        }
                        return;
                    }

                    if (onError) {
                        onError();
                        return;
                    }

                    var message = $.trim((json && json.error && json.error.message) || '');
                    var data = $.trim((json && json.error && json.error.data) || '');
                    if (!message && data) {
                        message = data;
                        data = '';
                    }
                    terminal.error('[ERROR] RPC: ' + (message || 'Unknown error') + (data ? ' (' + data + ')' : ''));
                })
                .fail(function (xhr, status) {
                    if (options.pause) {
                        terminal.resume();
                    }

                    if (onError) {
                        onError();
                        return;
                    }

                    if (status === 'abort') {
                        return;
                    }

                    var response = $.trim((xhr && xhr.responseText) || '');
                    terminal.error(
                        '[ERROR] AJAX: ' +
                            (status || 'Unknown error') +
                            (response ? '\nServer response:\n' + response : '')
                    );
                });
        }

        function rpcAuthenticated(terminal, method, params, onSuccess, onError, options) {
            var token = terminal.token();
            if (!token) {
                terminal.error('[ERROR] Access denied (no authentication token found)');
                return;
            }

            var rpcParams = [token, environment];
            if (params && params.length) {
                rpcParams.push.apply(rpcParams, params);
            }
            rpc(terminal, method, rpcParams, onSuccess, onError, options);
        }

        function renderOutput(output) {
            if (output === undefined || output === null || output === '') {
                return;
            }
            if (typeof output === 'string') {
                terminal.echo(output);
            } else if (output instanceof Array) {
                terminal.echo(output.map(JSON.stringify).join(' '));
            } else if (typeof output === 'object') {
                terminal.echo(JSON.stringify(output));
            } else {
                terminal.echo(String(output));
            }
        }

        function makePrompt() {
            var path = environment.path;
            if (path && path.length > settings.prompt_path_length) {
                path = '...' + path.slice(path.length - settings.prompt_path_length + 3);
            }

            return (
                '[[b;#d33682;]' +
                (environment.user || 'user') +
                ']' +
                '@[[b;#6c71c4;]' +
                (environment.hostname || settings.domain || 'web-console') +
                '] ' +
                (path || '~') +
                '$ '
            );
        }

        function updateEnvironment(terminal, data) {
            if (!data) {
                return;
            }
            $.extend(environment, data);
            terminal.set_prompt(makePrompt());
        }

        // Interpreter -- dispatches `cd` locally (as its own RPC method)
        // and everything else through `run`.
        function interpreter(command, terminal) {
            command = $.trim(command || '');
            if (!command) {
                return;
            }

            var parsed = $.terminal.split_command(command);
            var method;
            var params;

            if (parsed.name.toLowerCase() === 'cd') {
                method = 'cd';
                params = [parsed.args.length ? parsed.args[0] : ''];
            } else {
                method = 'run';
                params = [command];
            }

            rpcAuthenticated(terminal, method, params, function (result) {
                updateEnvironment(terminal, result.environment);
                renderOutput(result.output);
            });
        }

        function login(user, password, callback) {
            user = $.trim(user || '');
            password = $.trim(password || '');
            if (!user || !password) {
                callback(null);
                return;
            }

            rpc(
                terminal,
                'login',
                [user, password],
                function (result) {
                    if (result && result.token) {
                        environment.user = user;
                        updateEnvironment(terminal, result.environment);
                        renderOutput(result.output);
                        callback(result.token);
                        return;
                    }
                    callback(null);
                },
                function () {
                    callback(null);
                }
            );
        }

        function completion(pattern, callback) {
            // `this` is the terminal instance inside the completion callback
            var terminal = this;
            var state = terminal.export_view ? terminal.export_view() : terminal.state();
            var before = state.command.substring(0, state.position);

            rpcAuthenticated(
                terminal,
                'completion',
                [pattern, before],
                function (result) {
                    renderOutput(result.output);
                    if (result.completion && result.completion.length) {
                        callback(result.completion);
                    } else {
                        callback([]);
                    }
                },
                null,
                { pause: false }
            );
        }

        function logout() {
            silent_mode = true;
            try {
                terminal.clear();
                terminal.logout();
            } catch (error) {
                /* the terminal may already be torn down */
            }
            silent_mode = false;
        }

        var terminal = $('body').terminal(interpreter, {
            login: !no_login ? login : false,
            prompt: makePrompt(),
            greetings: !no_login ? 'You are authenticated' : '',
            completion: completion,
            onBlur: function () {
                return false;
            },
            exceptionHandler: function (error) {
                if (!silent_mode) {
                    terminal.exception(error);
                }
            }
        });

        if (no_login) {
            terminal.set_token('NO_LOGIN');
        } else {
            logout();
            window.addEventListener('beforeunload', function () {
                logout();
            });
        }

        if (banner_main) {
            terminal.echo(banner_main);
        }
        if (banner_extra) {
            terminal.echo(banner_extra);
        }
    });
})(jQuery);

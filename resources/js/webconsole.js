/**
 * Web-Console terminal bootstrapper.
 *
 * Targets jquery.terminal 2.x running on top of jQuery 3.x. Talks to the
 * PHP backend via plain JSON-RPC 2.0 over XHR ($.ajax) — the historical
 * $.jrpc plugin is gone in jquery.terminal 2.x, so we hand-roll the
 * envelope here.
 *
 * Wrapped in an IIFE so the module stays out of the global scope without
 * requiring a bundler. The server sets the optional `__NO_LOGIN__` global
 * to disable authentication entirely.
 */
/* global __NO_LOGIN__ */
(($) => {
    $(document).ready(() => {
        /** @type {{url: string, prompt_path_length: number, domain: string, is_small_window: boolean}} */
        const settings = {
            url: 'webconsole.php',
            prompt_path_length: 32,
            domain: document.domain || window.location.host,
            is_small_window: $(document).width() < 625,
        };

        /** @type {{user: string, hostname: string, path: string}} */
        const environment = { user: '', hostname: '', path: '' };

        /** Skip authentication entirely when the backend exposed __NO_LOGIN__ = true. */
        const noLogin = typeof __NO_LOGIN__ !== 'undefined' ? __NO_LOGIN__ : false;

        /** Suppress the jquery.terminal exception handler during programmatic teardown. */
        let silentMode = false;

        /** Monotonically increasing request id for the JSON-RPC envelope. */
        let rpcId = 0;

        // --- Banner ----------------------------------------------------------
        const bannerLink = 'https://github.com/netresearch/web-console';
        let bannerMain = 'Web Console';
        let bannerExtra = `${bannerLink}\n`;
        if (!settings.is_small_window) {
            bannerMain =
                '  _    _      _     _____                       _                ' +
                '\n | |  | |    | |   /  __ \\                     | |            ' +
                '\n | |  | | ___| |__ | /  \\/ ___  _ __  ___  ___ | | ___        ' +
                "\n | |/\\| |/ _ \\ '_ \\| |    / _ \\| '_ \\/ __|/ _ \\| |/ _ \\ " +
                '\n \\  /\\  /  __/ |_) | \\__/\\ (_) | | | \\__ \\ (_) | |  __/  ' +
                '\n  \\/  \\/ \\___|____/ \\____/\\___/|_| |_|___/\\___/|_|\\___| ';
            bannerExtra = `\n                 ${bannerLink}\n`;
        }

        /**
         * JSON-RPC call helper. Fire-and-forget; the callbacks own
         * success/error handling. `options.pause = false` keeps the
         * terminal responsive during tab completion.
         *
         * @param {object} terminal the jquery.terminal instance
         * @param {string} method RPC method name
         * @param {Array<unknown>} params positional params for the envelope
         * @param {(result: unknown) => void} [onSuccess] success handler invoked with the `result` body
         * @param {() => void} [onError] fallback invoked when the request fails
         * @param {{pause?: boolean}} [options] override defaults (currently: pause the terminal UI)
         */
        const rpc = (terminal, method, params, onSuccess, onError, options) => {
            const opts = $.extend({ pause: true }, options);
            if (opts.pause) {
                terminal.pause();
            }

            const payload = {
                jsonrpc: '2.0',
                method,
                params: params || [],
                id: ++rpcId,
            };

            $.ajax({
                url: settings.url,
                type: 'POST',
                dataType: 'json',
                contentType: 'application/json',
                data: JSON.stringify(payload),
            })
                .done((json) => {
                    if (opts.pause) {
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

                    let message = $.trim(json?.error?.message || '');
                    let data = $.trim(json?.error?.data || '');
                    if (!message && data) {
                        message = data;
                        data = '';
                    }
                    terminal.error(`[ERROR] RPC: ${message || 'Unknown error'}${data ? ` (${data})` : ''}`);
                })
                .fail((xhr, status) => {
                    if (opts.pause) {
                        terminal.resume();
                    }

                    if (onError) {
                        onError();
                        return;
                    }

                    if (status === 'abort') {
                        return;
                    }

                    const response = $.trim(xhr?.responseText || '');
                    terminal.error(
                        `[ERROR] AJAX: ${status || 'Unknown error'}${response ? `\nServer response:\n${response}` : ''}`,
                    );
                });
        };

        /**
         * Authenticated variant of {@link rpc}: prepends the session
         * token and the client-held environment snapshot.
         *
         * @param {object} terminal the jquery.terminal instance
         * @param {string} method RPC method name
         * @param {Array<unknown>} params positional params specific to the method
         * @param {(result: unknown) => void} [onSuccess]
         * @param {() => void} [onError]
         * @param {{pause?: boolean}} [options]
         */
        const rpcAuthenticated = (terminal, method, params, onSuccess, onError, options) => {
            const token = terminal.token();
            if (!token) {
                terminal.error('[ERROR] Access denied (no authentication token found)');
                return;
            }

            const rpcParams = [token, environment];
            if (params?.length) {
                rpcParams.push(...params);
            }
            rpc(terminal, method, rpcParams, onSuccess, onError, options);
        };

        /**
         * Pretty-print a command's stdout for the terminal. Strings pass
         * through, arrays and objects are JSON-serialised.
         *
         * @param {unknown} output
         */
        const renderOutput = (output) => {
            if (output === undefined || output === null || output === '') {
                return;
            }
            if (typeof output === 'string') {
                terminal.echo(output);
            } else if (Array.isArray(output)) {
                terminal.echo(output.map(JSON.stringify).join(' '));
            } else if (typeof output === 'object') {
                terminal.echo(JSON.stringify(output));
            } else {
                terminal.echo(String(output));
            }
        };

        /**
         * Render the `user@host path$` prompt with the current environment.
         * Long paths are truncated from the left with an ellipsis.
         *
         * @returns {string}
         */
        const makePrompt = () => {
            let path = environment.path;
            if (path && path.length > settings.prompt_path_length) {
                path = `...${path.slice(path.length - settings.prompt_path_length + 3)}`;
            }

            return (
                `[[b;#d33682;]${environment.user || 'user'}]` +
                `@[[b;#6c71c4;]${environment.hostname || settings.domain || 'web-console'}] ` +
                `${path || '~'}$ `
            );
        };

        /**
         * Merge a server-sent environment snapshot into the client-held
         * copy and re-render the prompt.
         *
         * @param {object} terminal
         * @param {{user?: string, hostname?: string, path?: string} | undefined} data
         */
        const updateEnvironment = (terminal, data) => {
            if (!data) {
                return;
            }
            $.extend(environment, data);
            terminal.set_prompt(makePrompt());
        };

        /**
         * Interpreter — dispatches `cd` locally (as its own RPC method)
         * and everything else through `run`.
         *
         * @param {string} command
         * @param {object} terminal
         */
        const interpreter = (command, terminal) => {
            const trimmed = $.trim(command || '');
            if (!trimmed) {
                return;
            }

            const parsed = $.terminal.split_command(trimmed);
            let method;
            let params;

            if (parsed.name.toLowerCase() === 'cd') {
                method = 'cd';
                params = [parsed.args.length ? parsed.args[0] : ''];
            } else {
                method = 'run';
                params = [trimmed];
            }

            rpcAuthenticated(terminal, method, params, (result) => {
                updateEnvironment(terminal, result.environment);
                renderOutput(result.output);
            });
        };

        /**
         * jquery.terminal login callback. Resolves with the session
         * token or null on failure.
         *
         * @param {string} user
         * @param {string} password
         * @param {(token: string | null) => void} callback
         */
        const login = (user, password, callback) => {
            const trimmedUser = $.trim(user || '');
            const trimmedPassword = $.trim(password || '');
            if (!trimmedUser || !trimmedPassword) {
                callback(null);
                return;
            }

            rpc(
                terminal,
                'login',
                [trimmedUser, trimmedPassword],
                (result) => {
                    if (result?.token) {
                        environment.user = trimmedUser;
                        updateEnvironment(terminal, result.environment);
                        renderOutput(result.output);
                        callback(result.token);
                        return;
                    }
                    callback(null);
                },
                () => {
                    callback(null);
                },
            );
        };

        /**
         * Tab-completion callback. Sends the prefix under the cursor to
         * the server and awaits a list of candidates. The broader command
         * context is not forwarded — the PHP side only does path-based
         * completion, so sending it would be dead protocol.
         *
         * Declared as a regular `function` so jquery.terminal's `this`
         * binding (the terminal instance) works.
         *
         * @this {object}
         * @param {string} pattern prefix under the cursor
         * @param {(completions: string[]) => void} callback
         */
        function completion(pattern, callback) {
            rpcAuthenticated(
                this,
                'completion',
                [pattern],
                (result) => {
                    renderOutput(result.output);
                    if (result.completion?.length) {
                        callback(result.completion);
                    } else {
                        callback([]);
                    }
                },
                null,
                { pause: false },
            );
        }

        /**
         * Tear down the terminal state on unload. Suppresses the
         * jquery.terminal exception handler because the DOM may already
         * be half-gone.
         */
        const logout = () => {
            silentMode = true;
            try {
                terminal.clear();
                terminal.logout();
            } catch (_error) {
                /* the terminal may already be torn down */
            }
            silentMode = false;
        };

        /**
         * Declared up front with `let` (rather than `const terminal =
         * $.terminal(...)`) so every helper closure that captures it —
         * interpreter/login/completion/logout/renderOutput — is outside
         * the temporal dead zone by the time jquery.terminal can fire a
         * synchronous callback during initialisation.
         *
         * @type {object | undefined}
         */
        let terminal;
        terminal = $('body').terminal(interpreter, {
            login: !noLogin ? login : false,
            prompt: makePrompt(),
            greetings: !noLogin ? 'You are authenticated' : '',
            completion,
            onBlur: () => false,
            exceptionHandler: (error) => {
                if (!silentMode && terminal) {
                    terminal.exception(error);
                }
            },
        });

        if (noLogin) {
            terminal.set_token('NO_LOGIN');
        } else {
            logout();
            window.addEventListener('beforeunload', () => {
                logout();
            });
        }

        if (bannerMain) {
            terminal.echo(bannerMain);
        }
        if (bannerExtra) {
            terminal.echo(bannerExtra);
        }
    });
})(jQuery);

<?php
/**
 * Inventaire de l'installation Claude Code de cet ordinateur.
 * Lecture seule : rien n'est écrit, aucune valeur secrète (env, compte) n'est renvoyée.
 */

function home_dir(): string
{
    $home = getenv('HOME');
    if (!$home && function_exists('posix_getpwuid')) {
        $home = posix_getpwuid(posix_geteuid())['dir'] ?? '';
    }
    return rtrim((string) $home, '/');
}

function is_local_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

function read_json(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/** En-tête YAML (clé: valeur) d'un fichier Markdown. */
function front_matter(string $file): array
{
    $head = ltrim((string) @file_get_contents($file, false, null, 0, 16384));
    if (!preg_match('/^---\R(.*?)\R---/s', $head, $m)) {
        return [];
    }
    $out = [];
    $key = null;
    foreach (preg_split('/\R/', $m[1]) as $line) {
        if (preg_match('/^([A-Za-z0-9_-]+):\s*(.*)$/', $line, $mm)) {
            $key = $mm[1];
            $value = trim($mm[2]);
            if (in_array($value, ['>', '|', '>-', '|-'], true)) {
                $value = '';
            }
            if (preg_match('/^(["\'])(.*)\1$/s', $value, $q)) {
                $value = $q[1] === '"' ? stripcslashes($q[2]) : str_replace("''", "'", $q[2]);
            }
            $out[$key] = $value;
        } elseif ($key !== null && preg_match('/^\s+\S/', $line)) {
            $out[$key] = trim($out[$key] . ' ' . trim($line));
        }
    }
    return $out;
}

/** Premier titre Markdown d'un fichier. */
function first_heading(string $file): string
{
    $head = (string) @file_get_contents($file, false, null, 0, 4096);
    $head = preg_replace('/^---\R.*?\R---\R/s', '', ltrim($head));
    return preg_match('/^#\s+(.+)$/m', $head, $m) ? trim($m[1]) : '';
}

/** Fichiers .md d'un dossier, sous-dossiers compris. */
function markdown_files(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && strtolower($f->getExtension()) === 'md') {
            $files[] = $f->getPathname();
        }
    }
    sort($files);
    return $files;
}

function short_path(string $path): string
{
    $home = home_dir();
    return str_starts_with($path, $home) ? '~' . substr($path, strlen($home)) : $path;
}

/** Nom du script lancé par une commande de hook. */
function hook_script(string $command): string
{
    if (preg_match_all('/[\w.-]+\.(?:py|sh|js|mjs|cmd|ts)\b/', $command, $m)) {
        return end($m[0]);
    }
    $words = preg_split('/\s+/', trim($command));
    return basename((string) end($words));
}

function claude_version(string $home, array $state): string
{
    $target = @readlink($home . '/.local/bin/claude');
    if ($target && preg_match('/^\d+\.\d+\.\d+/', basename($target), $m)) {
        return $m[0];
    }
    return (string) ($state['lastReleaseNotesSeen'] ?? '');
}

function build_inventory(): array
{
    $home = home_dir();
    $claude = $home . '/.claude';
    $settings = read_json($claude . '/settings.json');
    $state = read_json($home . '/.claude.json');
    $skillUsage = $state['skillUsage'] ?? [];

    $inv = [
        'readable' => is_dir($claude),
        'sources' => [],
        'skills' => [], 'agents' => [], 'commands' => [], 'rules' => [],
        'hooks' => [], 'mcp' => [], 'lsp' => [],
    ];

    $uses = static fn(string $call): int => (int) ($skillUsage[ltrim($call, '/')]['usageCount'] ?? 0);

    $addSkills = static function (string $dir, string $source, string $prefix) use (&$inv, $uses): void {
        foreach (glob($dir . '/*/SKILL.md') ?: [] as $file) {
            $fm = front_matter($file);
            $name = $fm['name'] ?? basename(dirname($file));
            $inv['skills'][] = [
                'source' => $source, 'prefix' => $prefix, 'name' => $name,
                'desc' => $fm['description'] ?? '', 'uses' => $uses($prefix . $name), 'active' => true,
            ];
        }
    };
    $addAgents = static function (string $dir, string $source, string $prefix) use (&$inv): void {
        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $fm = front_matter($file);
            $inv['agents'][] = [
                'source' => $source, 'prefix' => $prefix, 'name' => $fm['name'] ?? basename($file, '.md'),
                'desc' => $fm['description'] ?? '', 'uses' => 0, 'active' => true,
                'meta' => array_filter(['Outils' => $fm['tools'] ?? '', 'Modèle' => $fm['model'] ?? '']),
            ];
        }
    };
    $addCommands = static function (string $dir, string $source, string $prefix) use (&$inv, $uses): void {
        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $fm = front_matter($file);
            $name = basename($file, '.md');
            $inv['commands'][] = [
                'source' => $source, 'prefix' => '/' . $prefix, 'name' => $name,
                'desc' => $fm['description'] ?? first_heading($file), 'uses' => $uses($prefix . $name), 'active' => true,
                'meta' => array_filter(['Arguments' => $fm['argument-hint'] ?? '']),
            ];
        }
    };
    $addRules = static function (string $dir, string $source, bool $active) use (&$inv): void {
        foreach (markdown_files($dir) as $file) {
            $rel = substr($file, strlen($dir) + 1, -3);
            if (strtolower(basename($rel)) === 'readme') {
                continue;
            }
            $inv['rules'][] = [
                'source' => $source, 'prefix' => str_contains($rel, '/') ? dirname($rel) . '/' : '',
                'name' => basename($rel), 'desc' => first_heading($file), 'uses' => 0, 'active' => $active,
            ];
        }
    };
    $addHooks = static function (array $hooks, string $source, array $descriptions = []) use (&$inv): void {
        foreach ($hooks as $event => $entries) {
            foreach ((array) $entries as $i => $entry) {
                foreach ($entry['hooks'] ?? [] as $hook) {
                    $inv['hooks'][] = [
                        'source' => $source, 'event' => $event, 'matcher' => $entry['matcher'] ?? '',
                        'name' => $descriptions[$event][$i]['id'] ?? hook_script((string) ($hook['command'] ?? $hook['type'] ?? '')),
                        'desc' => $descriptions[$event][$i]['description'] ?? '',
                    ];
                }
            }
        }
    };
    $addMcp = static function (array $servers, string $source, string $scope, array $needsAuth = [], string $authPrefix = '') use (&$inv): void {
        foreach ($servers as $name => $server) {
            $server = (array) $server;
            $how = $server['url'] ?? trim(($server['command'] ?? '') . ' ' . implode(' ', (array) ($server['args'] ?? [])));
            $inv['mcp'][] = [
                'source' => $source, 'name' => $name, 'scope' => $scope, 'how' => $how,
                'type' => $server['type'] ?? (isset($server['url']) ? 'http' : 'stdio'),
                'auth' => in_array($authPrefix . $name, $needsAuth, true),
            ];
        }
    };

    // --- Personnel : ~/.claude
    $inv['sources']['perso'] = [
        'label' => 'Personnel', 'kind' => 'perso', 'version' => '', 'enabled' => true,
        'desc' => 'Ce que vous avez ajouté vous-même dans ~/.claude.', 'origin' => '~/.claude',
    ];
    $addSkills($claude . '/skills', 'perso', '');
    $addAgents($claude . '/agents', 'perso', '');
    $addCommands($claude . '/commands', 'perso', '');
    $addRules($claude . '/rules', 'perso', true);
    $addHooks($settings['hooks'] ?? [], 'perso');
    $addMcp($state['mcpServers'] ?? [], 'perso', 'Tous les projets');

    // --- Plugins installés
    $installed = read_json($claude . '/plugins/installed_plugins.json')['plugins'] ?? [];
    $marketplaces = read_json($claude . '/plugins/known_marketplaces.json');
    $catalogs = [];
    foreach ($installed as $key => $installs) {
        [$name, $market] = array_pad(explode('@', $key, 2), 2, '');
        $install = $installs[0] ?? [];
        $path = (string) ($install['installPath'] ?? '');
        $manifest = read_json($path . '/.claude-plugin/plugin.json');
        if (!isset($catalogs[$market])) {
            $catalog = read_json(($marketplaces[$market]['installLocation'] ?? '') . '/.claude-plugin/marketplace.json');
            $catalogs[$market] = array_column($catalog['plugins'] ?? [], null, 'name');
        }
        $entry = $catalogs[$market][$name] ?? [];
        $enabled = (bool) ($settings['enabledPlugins'][$key] ?? false);
        $usage = $state['pluginUsage'][$key] ?? [];

        $inv['sources'][$key] = [
            'label' => $name, 'kind' => 'plugin', 'enabled' => $enabled,
            'version' => (string) ($manifest['version'] ?? $install['version'] ?? ''),
            'desc' => (string) ($manifest['description'] ?? $entry['description'] ?? ''),
            'author' => (string) ($manifest['author']['name'] ?? $entry['author']['name'] ?? ''),
            'homepage' => (string) ($manifest['homepage'] ?? $manifest['repository'] ?? ''),
            'origin' => $market, 'installedAt' => $install['installedAt'] ?? '', 'updatedAt' => $install['lastUpdated'] ?? '',
            'uses' => (int) ($usage['usageCount'] ?? 0),
        ];

        // Le manifeste peut déplacer un dossier : chemin unique ou liste de chemins relatifs.
        $dirs = static function (string $type) use ($manifest, $path): array {
            $list = array_filter((array) ($manifest[$type] ?? []), 'is_string') ?: [$type];
            return array_map(static fn($dir) => rtrim($path . '/' . preg_replace('#^\./#', '', $dir), '/'), $list);
        };
        foreach ($dirs('skills') as $dir) {
            $addSkills($dir, $key, $name . ':');
        }
        foreach ($dirs('agents') as $dir) {
            $addAgents($dir, $key, $name . ':');
        }
        foreach ($dirs('commands') as $dir) {
            $addCommands($dir, $key, $name . ':');
        }
        foreach (glob($path . '/rules/*', GLOB_ONLYDIR) ?: [] as $set) {
            $before = count($inv['rules']);
            $addRules($set, $key, false);
            for ($i = $before; $i < count($inv['rules']); $i++) {
                $inv['rules'][$i]['prefix'] = basename($set) . '/';
            }
        }
        $hooksOn = $settings['pluginConfigs'][$key]['options']['hooks_enabled'] ?? true;
        if ($hooksOn) {
            $hooksFile = read_json($path . '/hooks/hooks.json');
            $addHooks($hooksFile['hooks'] ?? [], $key, read_json($path . '/hooks/hooks.metadata.json')['entries'] ?? []);
        }
        $mcpFile = read_json($path . '/.mcp.json');
        $addMcp($mcpFile['mcpServers'] ?? $mcpFile, $key, 'Fourni par le plugin');
        foreach ($entry['lspServers'] ?? $manifest['lspServers'] ?? [] as $lspName => $lsp) {
            $inv['lsp'][] = [
                'source' => $key, 'name' => $lspName, 'how' => (string) ($lsp['command'] ?? ''),
                'languages' => implode(', ', array_unique(array_values((array) ($lsp['extensionToLanguage'] ?? [])))),
            ];
        }
    }

    // --- Synchronisé depuis claude.ai : plugins et skills du compte
    $needsAuth = $state['mcpNeedsAuthNoticed'] ?? [];
    foreach (glob($claude . '/plugins/synced/*/manifest.json') ?: [] as $file) {
        foreach (read_json($file)['plugins'] ?? [] as $plugin) {
            $name = (string) $plugin['name'];
            $dir = (glob(dirname($file) . '/' . $name . '{,~*}', GLOB_BRACE | GLOB_ONLYDIR) ?: [''])[0];
            $key = $name . '@claude.ai';
            $manifest = read_json($dir . '/.claude-plugin/plugin.json');
            $inv['sources'][$key] = [
                'label' => $name, 'kind' => 'claudeai', 'enabled' => $dir !== '',
                'version' => (string) ($manifest['version'] ?? ''), 'desc' => (string) ($plugin['description'] ?? ''),
                'author' => (string) ($manifest['author']['name'] ?? ''), 'homepage' => '',
                'origin' => 'claude.ai (' . ($plugin['marketplaceName'] ?? 'compte') . ')',
                'installedAt' => '', 'updatedAt' => $plugin['updatedAt'] ?? '', 'uses' => 0,
            ];
            $addSkills($dir . '/skills', $key, $name . ':');
            $addAgents($dir . '/agents', $key, $name . ':');
            $addCommands($dir . '/commands', $key, $name . ':');
            $mcpFile = read_json($dir . '/.mcp.json');
            $addMcp($mcpFile['mcpServers'] ?? $mcpFile, $key, 'Fourni par le plugin', $needsAuth, 'plugin:' . $name . ':');
        }
    }
    $inv['sources']['claudeai'] = [
        'label' => 'claude.ai', 'kind' => 'claudeai', 'version' => '', 'enabled' => true,
        'desc' => 'Skills et connecteurs de votre compte claude.ai, synchronisés sur cet ordinateur.', 'origin' => 'Compte claude.ai',
    ];
    foreach (glob($claude . '/skills/synced/*/manifest.json') ?: [] as $file) {
        foreach (read_json($file)['skills'] ?? [] as $skill) {
            $inv['skills'][] = [
                'source' => 'claudeai', 'prefix' => 'anthropic-skills:', 'name' => (string) $skill['name'],
                'desc' => (string) ($skill['description'] ?? ''), 'uses' => $uses('anthropic-skills:' . $skill['name']), 'active' => true,
            ];
        }
    }
    foreach ($state['claudeAiMcpEverConnected'] ?? [] as $connector) {
        $inv['mcp'][] = [
            'source' => 'claudeai', 'name' => preg_replace('/^claude\.ai\s+/', '', $connector),
            'scope' => 'Connecteur du compte', 'how' => 'claude.ai', 'type' => 'connecteur', 'auth' => false,
        ];
    }

    // --- Projets : CLAUDE.md, mémoire, serveurs MCP propres au projet
    $inv['projects'] = [];
    foreach ($state['projects'] ?? [] as $path => $project) {
        if (!is_dir($path)) {
            continue;
        }
        $slug = preg_replace('/[^A-Za-z0-9]/', '-', $path);
        $memories = array_filter(
            glob($claude . '/projects/' . $slug . '/memory/*.md') ?: [],
            static fn($f) => basename($f) !== 'MEMORY.md'
        );
        $row = [
            'path' => short_path($path),
            'claudeMd' => is_file($path . '/CLAUDE.md'),
            'memories' => count($memories),
            'mcp' => array_keys($project['mcpServers'] ?? []),
            'local' => array_filter([
                'skills' => count(glob($path . '/.claude/skills/*/SKILL.md') ?: []),
                'agents' => count(glob($path . '/.claude/agents/*.md') ?: []),
                'commandes' => count(glob($path . '/.claude/commands/*.md') ?: []),
            ]),
        ];
        if ($row['claudeMd'] || $row['memories'] || $row['mcp'] || $row['local']) {
            $inv['projects'][] = $row;
        }
    }
    usort($inv['projects'], static fn($a, $b) => strcasecmp($a['path'], $b['path']));

    foreach (['skills', 'agents', 'commands', 'rules'] as $type) {
        usort($inv[$type], static fn($a, $b) => strcasecmp($a['prefix'] . $a['name'], $b['prefix'] . $b['name']));
    }

    $inv['globalInstructions'] = is_file($claude . '/CLAUDE.md') ? (string) file_get_contents($claude . '/CLAUDE.md') : '';
    $inv['marketplaces'] = [];
    foreach ($marketplaces as $name => $market) {
        $inv['marketplaces'][] = [
            'name' => $name,
            'source' => (string) ($market['source']['repo'] ?? $market['source']['url'] ?? ''),
            'updatedAt' => $market['lastUpdated'] ?? '',
        ];
    }
    $inv['general'] = [
        'version' => claude_version($home, $state),
        'model' => (string) ($settings['model'] ?? 'par défaut'),
        'installMethod' => (string) ($state['installMethod'] ?? ''),
        'firstStart' => (string) ($state['firstStartTime'] ?? ''),
        'startups' => (int) ($state['numStartups'] ?? 0),
        'settings' => array_filter($settings, static fn($v) => is_scalar($v)),
    ];

    return $inv;
}

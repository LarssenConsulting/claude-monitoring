<?php
/**
 * Données d'usage de Claude Code, lues dans ~/.claude.
 * Les transcriptions sont lourdes : leurs agrégats sont mis en cache hors racine web.
 */

/** Messages envoyés, d'après ~/.claude/history.jsonl (une ligne par message). */
function usage_history(string $claude): array
{
    $days = [];
    $projects = [];
    $hours = array_fill(0, 24, 0);
    $sessions = [];
    $total = 0;

    $fh = @fopen($claude . '/history.jsonl', 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $entry = json_decode($line, true);
            if (!isset($entry['timestamp'])) {
                continue;
            }
            $time = intdiv((int) $entry['timestamp'], 1000);
            $day = date('Y-m-d', $time);
            $days[$day] = ($days[$day] ?? 0) + 1;
            $hours[(int) date('G', $time)]++;
            $total++;
            if (!empty($entry['project'])) {
                $projects[$entry['project']] = ($projects[$entry['project']] ?? 0) + 1;
            }
            if (!empty($entry['sessionId'])) {
                $sessions[$entry['sessionId']] = true;
            }
        }
        fclose($fh);
    }
    ksort($days);
    arsort($projects);

    return [
        'days' => $days, 'projects' => $projects, 'hours' => $hours,
        'sessions' => count($sessions), 'total' => $total,
        'from' => array_key_first($days) ?? '', 'to' => array_key_last($days) ?? '',
    ];
}

/**
 * Agrégats d'une transcription : tokens par jour et par modèle, outils appelés.
 * Une même réponse est écrite sur plusieurs lignes : on ne garde que la dernière par identifiant.
 */
function parse_transcript(string $file): array
{
    $messages = [];
    $tools = [];
    $cwd = '';

    $fh = @fopen($file, 'r');
    if (!$fh) {
        return ['d' => [], 't' => [], 'cwd' => ''];
    }
    while (($line = fgets($fh)) !== false) {
        if (strpos($line, '"assistant"') === false) {
            continue;
        }
        $entry = json_decode($line, true);
        $message = $entry['message'] ?? null;
        if (($entry['type'] ?? '') !== 'assistant' || !is_array($message)) {
            continue;
        }
        if ($cwd === '') {
            $cwd = (string) ($entry['cwd'] ?? '');
        }
        foreach ((array) ($message['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_use' && isset($block['id'])) {
                $tools[$block['id']] = (string) ($block['name'] ?? '?');
            }
        }
        $usage = $message['usage'] ?? null;
        $model = (string) ($message['model'] ?? '');
        if (!is_array($usage) || $model === '' || $model[0] === '<') {
            continue;
        }
        $messages[$message['id'] ?? $entry['uuid'] ?? count($messages)] = [
            date('Y-m-d', strtotime((string) ($entry['timestamp'] ?? 'now'))),
            $model,
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_creation_input_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? 0),
        ];
    }
    fclose($fh);

    $days = [];
    foreach ($messages as [$day, $model, $in, $out, $write, $read]) {
        $sum = $days[$day][$model] ?? [0, 0, 0, 0, 0];
        $days[$day][$model] = [$sum[0] + $in, $sum[1] + $out, $sum[2] + $write, $sum[3] + $read, $sum[4] + 1];
    }
    return ['d' => $days, 't' => array_count_values($tools), 'cwd' => $cwd];
}

/** Tokens et outils, d'après les transcriptions encore présentes dans ~/.claude/projects. */
function usage_transcripts(string $claude, string $cacheFile): array
{
    $root = $claude . '/projects';
    $cache = read_json($cacheFile)['files'] ?? [];
    $files = [];
    $dirty = false;

    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || $f->getExtension() !== 'jsonl') {
                continue;
            }
            $path = $f->getPathname();
            $known = $cache[$path] ?? null;
            if ($known && $known['m'] === $f->getMTime() && $known['s'] === $f->getSize()) {
                $files[$path] = $known;
                continue;
            }
            $files[$path] = parse_transcript($path) + ['m' => $f->getMTime(), 's' => $f->getSize()];
            $dirty = true;
        }
    }
    if ($dirty || count($files) !== count($cache)) {
        @mkdir(dirname($cacheFile), 0700, true);
        @file_put_contents($cacheFile, json_encode(['files' => $files]), LOCK_EX);
    }

    $add = static function (array $sum, array $row): array {
        foreach ($row as $i => $value) {
            $sum[$i] = ($sum[$i] ?? 0) + $value;
        }
        return $sum;
    };
    $days = [];
    $models = [];
    $projects = [];
    $tools = [];
    $sessions = 0;
    foreach ($files as $path => $file) {
        if (!$file['d']) {
            continue;
        }
        $main = dirname($path, 2) === $root;
        $sessions += $main ? 1 : 0;
        $project = $file['cwd'] !== '' ? $file['cwd'] : basename(dirname($path));
        $projects[$project] ??= ['tokens' => [], 'sessions' => 0];
        $projects[$project]['sessions'] += $main ? 1 : 0;
        foreach ($file['d'] as $day => $byModel) {
            foreach ($byModel as $model => $row) {
                $days[$day] = $add($days[$day] ?? [], $row);
                $models[$model] = $add($models[$model] ?? [], $row);
                $projects[$project]['tokens'] = $add($projects[$project]['tokens'], $row);
            }
        }
        foreach ($file['t'] as $tool => $count) {
            $tools[$tool] = ($tools[$tool] ?? 0) + $count;
        }
    }
    ksort($days);
    uasort($models, static fn($a, $b) => $b[1] <=> $a[1]);
    uasort($projects, static fn($a, $b) => $b['tokens'][1] <=> $a['tokens'][1]);
    arsort($tools);

    return [
        'days' => $days, 'models' => $models, 'projects' => $projects, 'tools' => $tools,
        'sessions' => $sessions, 'total' => array_reduce($days, $add, [0, 0, 0, 0, 0]),
        'from' => array_key_first($days) ?? '', 'to' => array_key_last($days) ?? '',
        'cacheWritable' => is_file($cacheFile),
    ];
}

/** Complète une série par jour avec des zéros, de $from à $to inclus. */
function fill_days(array $days, string $from, string $to): array
{
    $out = [];
    for ($t = strtotime($from . ' 12:00'); $t <= strtotime($to . ' 12:00'); $t += 86400) {
        $day = date('Y-m-d', $t);
        $out[$day] = $days[$day] ?? 0;
    }
    return $out;
}

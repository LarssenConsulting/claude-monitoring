<?php
/**
 * Sessions Claude Code ouvertes sur cet ordinateur, lues dans ~/.claude/sessions et à la fin des transcriptions.
 * Aucun contenu n'est renvoyé : ni message, ni commande, ni chemin de fichier, ni titre de session
 * (Claude Code déduit ce titre des messages).
 */

/** Taille lue à la fin d'une transcription : assez pour les derniers appels, sans relire tout le fichier. */
const LIVE_TAIL_BYTES = 1048576;

/** Nombre d'appels d'outils affichés par session. */
const LIVE_EVENTS = 15;

/** Sessions dont le processus tourne encore, celles en activité d'abord. */
function live_sessions(string $claude): array
{
    $sessions = [];
    foreach (glob($claude . '/sessions/*.json') ?: [] as $file) {
        $data = read_json($file);
        $pid = (int) ($data['pid'] ?? 0);
        $id = (string) ($data['sessionId'] ?? '');
        // Un fichier peut rester après un plantage : on ne garde que les processus vivants.
        if ($pid <= 0 || !preg_match('/^[0-9a-f-]{36}\z/', $id) || !process_alive($pid)) {
            continue;
        }
        $cwd = (string) ($data['cwd'] ?? '');
        $status = (string) ($data['status'] ?? '');
        $version = (string) ($data['version'] ?? '');

        // La transcription est rangée sous le dossier de travail, dont les caractères spéciaux deviennent des tirets.
        $transcript = $claude . '/projects/' . preg_replace('/[^A-Za-z0-9]/', '-', $cwd) . '/' . $id . '.jsonl';
        if (!is_file($transcript)) {
            $transcript = (glob($claude . '/projects/*/' . $id . '.jsonl') ?: [''])[0];
        }

        $sessions[] = [
            'project' => $cwd !== '' ? short_path($cwd) : '',
            'status' => in_array($status, ['busy', 'idle'], true) ? $status : 'other',
            'started' => intdiv((int) ($data['startedAt'] ?? 0), 1000),
            'version' => preg_match('/^\d+(?:\.\d+){1,3}\z/', $version) ? $version : '',
        ] + live_activity($transcript);
    }
    usort($sessions, static fn($a, $b) => [$b['status'] === 'busy', $b['written']] <=> [$a['status'] === 'busy', $a['written']]);

    return $sessions;
}

function process_alive(int $pid): bool
{
    if (!function_exists('posix_kill')) {
        return true;
    }
    // Le code 1 (EPERM) signale un processus qui existe mais appartient à un autre compte.
    return posix_kill($pid, 0) || posix_get_last_error() === 1;
}

/**
 * Fin d'une transcription : dernière écriture, modèle, taille du contexte et derniers outils appelés.
 * Un outil est « en cours » tant que son résultat n'a pas été écrit.
 */
function live_activity(string $file): array
{
    $activity = ['written' => 0, 'model' => '', 'context' => 0, 'events' => []];
    $fh = $file !== '' ? @fopen($file, 'r') : false;
    if (!$fh) {
        return $activity;
    }
    $activity['written'] = (int) filemtime($file);
    $size = (int) filesize($file);
    if ($size > LIVE_TAIL_BYTES) {
        fseek($fh, $size - LIVE_TAIL_BYTES);
        fgets($fh); // première ligne tronquée
    }

    $events = [];
    while (($line = fgets($fh)) !== false) {
        $entry = json_decode($line, true);
        $message = $entry['message'] ?? null;
        if (!is_array($message) || !empty($entry['isSidechain']) || !is_array($message['content'] ?? null)) {
            continue;
        }
        if (($entry['type'] ?? '') === 'assistant') {
            if (preg_match('/^[a-z0-9.-]{1,60}\z/', (string) ($message['model'] ?? ''))) {
                $activity['model'] = $message['model'];
            }
            $usage = $message['usage'] ?? [];
            $context = (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0);
            if ($context > 0) {
                $activity['context'] = $context;
            }
        }
        foreach ($message['content'] as $block) {
            $type = is_array($block) ? ($block['type'] ?? '') : '';
            if ($type === 'tool_use' && is_string($block['id'] ?? null)) {
                $label = live_tool_label($block);
                if ($label !== '') {
                    $events[$block['id']] = ['time' => (int) strtotime((string) ($entry['timestamp'] ?? '')), 'label' => $label, 'pending' => true];
                }
            } elseif ($type === 'tool_result' && isset($events[$block['tool_use_id'] ?? ''])) {
                $events[$block['tool_use_id']]['pending'] = false;
            }
        }
    }
    fclose($fh);
    $activity['events'] = array_reverse(array_slice(array_values($events), -LIVE_EVENTS));

    return $activity;
}

/**
 * Nom affichable d'un appel d'outil : le nom de l'outil, suivi du skill ou du type d'agent lancé.
 * Jamais le reste de l'appel (commande, chemin, texte), qui peut contenir un secret.
 */
function live_tool_label(array $block): string
{
    $name = (string) ($block['name'] ?? '');
    if (!preg_match('/^[A-Za-z][\w.-]{0,79}\z/', $name)) {
        return '';
    }
    if (preg_match('/^mcp__(.+?)__(.+)\z/', $name, $m)) {
        return $m[1] . ' · ' . $m[2];
    }
    $input = is_array($block['input'] ?? null) ? $block['input'] : [];
    $detail = ['Skill' => $input['skill'] ?? '', 'Agent' => $input['subagent_type'] ?? '', 'Task' => $input['subagent_type'] ?? ''][$name] ?? '';

    return is_string($detail) && preg_match('/^[\w.:-]{1,80}\z/', $detail) ? $name . ' · ' . $detail : $name;
}

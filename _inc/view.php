<?php
/** Fonctions d'affichage de la page d'inventaire. */

date_default_timezone_set('Europe/Paris');

/** Polices et feuille de style, communes aux deux pages. */
function head_assets(): void
{
    $css = dirname(__DIR__) . '/assets/css/style.css';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500;600&amp;family=Fira+Sans:wght@400;500;600;700&amp;display=swap">' . "\n";
    echo '  <link rel="stylesheet" href="assets/css/style.css?v=' . filemtime($css) . '">' . "\n";
}

/**
 * Haut de page commun : barre d'application, titre, chiffres clés.
 * $meta : libellé => valeur ; $kpis : [libellé, valeur, lien ou null] ; $sections : ancre => libellé.
 */
function page_header(string $current, string $title, string $lede, array $meta, array $kpis, array $sections, bool $search): void
{
    $pages = ['installation' => ['./', 'Installation'], 'usage' => ['usage.php', 'Usage'], 'live' => ['live.php', 'Live']];

    echo '<div class="backdrop" aria-hidden="true"></div>';
    echo '<a class="skip" href="' . e(array_key_first($sections)) . '">Aller au contenu</a>';
    echo '<header class="topbar"><div class="wrap">';
    echo '<div class="topbar-main"><a class="brand" href="./"><span class="brand-dot" aria-hidden="true"></span>Claude Monitoring</a>';
    echo '<nav class="pages" aria-label="Pages">';
    foreach ($pages as $key => [$href, $label]) {
        echo '<a href="' . $href . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    echo '</nav>';
    if ($search) {
        echo '<div class="search"><label class="sr" for="q">Rechercher dans la page</label>'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>'
            . '<input id="q" type="search" placeholder="Rechercher" autocomplete="off"><kbd>/</kbd></div>';
    }
    echo '</div><nav class="sections" aria-label="Sections">';
    foreach ($sections as $href => $label) {
        echo '<a href="' . e($href) . '">' . e($label) . '</a>';
    }
    echo '</nav></div></header>';

    echo '<div class="wrap hero"><h1>' . e($title) . '</h1><p>' . e($lede) . '</p>';
    if ($meta) {
        echo '<ul class="meta">';
        foreach ($meta as $label => $value) {
            echo '<li><span>' . e($label) . '</span> ' . e($value) . '</li>';
        }
        echo '</ul>';
    }
    echo '<dl class="kpis">';
    foreach ($kpis as [$label, $value, $href]) {
        $inner = '<dt>' . e($label) . '</dt><dd>' . e($value) . '</dd>';
        echo $href ? '<a class="kpi" href="' . e($href) . '">' . $inner . '</a>' : '<div class="kpi">' . $inner . '</div>';
    }
    echo '</dl></div>';
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Date ISO 8601 -> « 8 octobre 2026 ». */
function date_fr(string $iso): string
{
    $time = $iso !== '' ? strtotime($iso) : false;
    if (!$time) {
        return '';
    }
    $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $day = (int) date('j', $time);
    return ($day === 1 ? '1er' : $day) . ' ' . $months[(int) date('n', $time) - 1] . ' ' . date('Y', $time);
}

/** Durée en secondes -> « 45 s », « 12 min », « 3 h 05 », « 2 j ». */
function duration_fr(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . ' s';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . ' h ' . str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT);
    }
    return intdiv($seconds, 86400) . ' j';
}

function anchor(string $text): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
}

function count_by_source(array $items): array
{
    $counts = [];
    foreach ($items as $item) {
        $counts[$item['source']] = ($counts[$item['source']] ?? 0) + 1;
    }
    return $counts;
}

/** Cellule du tableau de synthèse : nombre cliquable, ou tiret quand il n'y a rien. */
function matrix_cell(int $count, string $href, bool $inactive = false): string
{
    if ($count === 0) {
        return '<td class="num none">–</td>';
    }
    $size = $count >= 50 ? ' big' : ($count >= 10 ? ' mid' : '');
    return '<td class="num' . $size . ($inactive ? ' inactive' : '') . '"><a href="' . e($href) . '">' . $count . '</a></td>';
}

/** Liste d'éléments (skills, agents, commandes, règles) regroupés par source. */
function render_groups(string $section, array $items, array $sources): void
{
    $groups = [];
    foreach ($items as $item) {
        $groups[$item['source']][] = $item;
    }
    foreach ($sources as $key => $source) {
        if (empty($groups[$key])) {
            continue;
        }
        $list = $groups[$key];
        $open = count($list) <= 40 ? ' open' : '';
        echo '<details class="group" id="' . e($section . '--' . anchor($key)) . '"' . $open . '>';
        echo '<summary><h3>' . e($source['label']) . '</h3>';
        if ($source['version'] !== '') {
            echo '<span class="version">' . e($source['version']) . '</span>';
        }
        echo '<span class="count" data-count data-total="' . count($list) . '">' . count($list) . '</span></summary>';
        echo '<div class="items">';
        foreach ($list as $item) {
            echo '<details class="item' . ($item['active'] ? '' : ' inactive') . '" data-search>';
            // Le nom du plugin est déjà dans le titre du groupe : on ne le répète que dans le détail.
            $short = preg_replace('/[\w.-]+:$/', '', $item['prefix']);
            if ($short !== $item['prefix']) {
                $item['meta'] = ['Nom complet' => $item['prefix'] . $item['name']] + ($item['meta'] ?? []);
            }
            echo '<summary><span class="item-name"><span class="prefix">' . e($short) . '</span>' . e($item['name']) . '</span>';
            if ($item['uses'] > 0) {
                echo '<span class="uses" title="Utilisé ' . $item['uses'] . ' fois">×' . $item['uses'] . '</span>';
            }
            echo '<span class="item-desc">' . e($item['desc']) . '</span></summary>';
            echo '<p>' . ($item['desc'] !== '' ? e($item['desc']) : 'Pas de description.') . '</p>';
            foreach ($item['meta'] ?? [] as $label => $value) {
                echo '<p class="meta"><span>' . e($label) . '</span> ' . e($value) . '</p>';
            }
            echo '</details>';
        }
        echo '</div></details>';
    }
    echo '<p class="empty" hidden>Rien ne correspond à cette recherche.</p>';
}

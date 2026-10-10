<?php
/** Graphiques SVG générés côté serveur. Les couleurs viennent des classes CSS, jamais d'attributs de style. */

/** 12345 -> « 12 345 ». */
function nf($number): string
{
    return number_format((float) $number, 0, ',', "\u{202F}");
}

/** 3 200 000 -> « 3,2 M ». */
function short_number($number): string
{
    $number = (float) $number;
    foreach ([[1e9, ' Md'], [1e6, ' M'], [1e3, ' k']] as [$limit, $unit]) {
        if ($number >= $limit) {
            $value = $number / $limit;
            return number_format($value, $value < 10 ? 1 : 0, ',', '') . $unit;
        }
    }
    return (string) round($number);
}

function nice_max(float $max): float
{
    if ($max <= 0) {
        return 1;
    }
    $power = 10 ** floor(log10($max));
    foreach ([1, 2, 2.5, 5, 10] as $step) {
        if ($step * $power >= $max) {
            return $step * $power;
        }
    }
    return $max;
}

/**
 * Histogramme en colonnes, une seule série.
 * $values : libellé de l'infobulle => valeur ; $ticks : index de la colonne => texte sous l'axe.
 */
function svg_columns(array $values, array $ticks, callable $format, string $label): string
{
    $width = 1160; $height = 220; $left = 58; $bottom = 26; $top = 10;
    $plotW = $width - $left;
    $plotH = $height - $bottom - $top;
    $count = max(1, count($values));
    $max = nice_max((float) max($values ?: [1]));
    $step = $plotW / $count;
    $bar = min(30, max(2, $step - 2));

    $svg = '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="' . e($label) . '">';
    foreach ([0, .5, 1] as $fraction) {
        $y = $top + $plotH * (1 - $fraction);
        $svg .= '<line class="grid" x1="' . $left . '" x2="' . $width . '" y1="' . $y . '" y2="' . $y . '"/>';
        $svg .= '<text class="axis" x="' . ($left - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . e($format($max * $fraction)) . '</text>';
    }
    $i = 0;
    foreach ($values as $name => $value) {
        $x = $left + $i * $step + ($step - $bar) / 2;
        $h = $value > 0 ? max(1, round($plotH * $value / $max, 1)) : 0;
        if ($h > 0) {
            $svg .= '<rect class="bar" x="' . round($x, 1) . '" y="' . ($top + $plotH - $h) . '" width="' . round($bar, 1) . '" height="' . $h . '" rx="2">'
                . '<title>' . e($name . ' : ' . $format($value)) . '</title></rect>';
        }
        if (isset($ticks[$i])) {
            $svg .= '<text class="axis" x="' . round($x + $bar / 2, 1) . '" y="' . ($height - 7) . '" text-anchor="middle">' . e($ticks[$i]) . '</text>';
        }
        $i++;
    }
    return $svg . '</svg>';
}

/** Calendrier d'activité : une colonne par semaine, une case par jour, teinte selon le volume. */
function svg_heatmap(array $days, string $from, string $to, string $unit): string
{
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $cell = 13; $step = 16; $left = 34; $top = 20;

    $values = array_values(array_filter($days));
    sort($values);
    $quantile = static fn(float $q) => $values ? $values[(int) floor($q * (count($values) - 1))] : 0;
    $limits = [$quantile(.25), $quantile(.5), $quantile(.75)];

    $start = strtotime('monday this week', strtotime($from . ' 12:00'));
    $end = strtotime($to . ' 12:00');
    $weeks = (int) floor(($end - $start) / 604800) + 1;

    $svg = '<svg class="heatmap" width="' . ($left + $weeks * $step + 24) . '" height="' . ($top + 7 * $step) . '" role="img" aria-label="Calendrier d\'activité">';
    foreach ([0 => 'lun.', 2 => 'mer.', 4 => 'ven.', 6 => 'dim.'] as $row => $name) {
        $svg .= '<text class="axis" x="0" y="' . ($top + $row * $step + 11) . '">' . $name . '</text>';
    }
    $lastMonth = -1;
    for ($week = 0; $week < $weeks; $week++) {
        $monday = $start + $week * 604800;
        $month = (int) date('n', $monday);
        if ($month !== $lastMonth) {
            $svg .= '<text class="axis" x="' . ($left + $week * $step) . '" y="12">' . $months[$month - 1] . '</text>';
            $lastMonth = $month;
        }
        for ($row = 0; $row < 7; $row++) {
            $time = $monday + $row * 86400;
            if ($time > $end) {
                break;
            }
            $day = date('Y-m-d', $time);
            $value = $days[$day] ?? 0;
            $level = $value === 0 ? 0 : ($value <= $limits[0] ? 1 : ($value <= $limits[1] ? 2 : ($value <= $limits[2] ? 3 : 4)));
            $svg .= '<rect class="q' . $level . '" x="' . ($left + $week * $step) . '" y="' . ($top + $row * $step) . '" width="' . $cell . '" height="' . $cell . '" rx="2">'
                . '<title>' . e(date_fr($day) . ' : ' . nf($value) . ' ' . $unit) . '</title></rect>';
        }
    }
    return $svg . '</svg>';
}

/** Classement en barres horizontales : libellé, barre proportionnelle, valeur. */
function bar_rows(array $rows, callable $format, string $labelHead, string $valueHead): string
{
    $max = max(1, max($rows ?: [1]));
    $html = '<table class="bars"><thead><tr><th scope="col">' . e($labelHead) . '</th><th scope="col" class="num" colspan="2">' . e($valueHead) . '</th></tr></thead><tbody>';
    foreach ($rows as $label => $value) {
        $html .= '<tr><th scope="row">' . e($label) . '</th>'
            . '<td class="track"><svg viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true"><rect class="bar" width="' . round(100 * $value / $max, 2) . '" height="8" rx="1"/></svg></td>'
            . '<td class="num">' . e($format($value)) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

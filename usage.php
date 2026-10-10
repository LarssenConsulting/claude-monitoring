<?php
require __DIR__ . '/_inc/inventory.php';
require __DIR__ . '/_inc/view.php';
require __DIR__ . '/_inc/usage.php';
require __DIR__ . '/_inc/charts.php';

// Cette page expose l'activité locale : elle ne répond qu'à cet ordinateur.
if (!is_local_request()) {
    http_response_code(403);
    exit("Accès refusé. Cette page ne répond qu'à cet ordinateur, sur localhost ou sur un nom d'hôte déclaré dans config.local.php (voir le README).");
}
set_time_limit(180);

$claude = home_dir() . '/.claude';
$history = usage_history($claude);
$tokens = usage_transcripts($claude, dirname(__DIR__) . '/_cache/usage.json');
$state = read_json(home_dir() . '/.claude.json');
$today = date('Y-m-d');

// Activité : messages par jour sur un an au plus.
$from = max($history['from'] ?: $today, date('Y-m-d', strtotime('-52 weeks')));
$activeDays = count(array_filter($history['days']));

// Heures de la journée.
$hours = [];
foreach ($history['hours'] as $hour => $count) {
    $hours[$hour . ' h'] = $count;
}
$hourTicks = [];
for ($hour = 0; $hour < 24; $hour += 3) {
    $hourTicks[$hour] = $hour . ' h';
}

// Projets : les plus sollicités depuis le début de l'historique.
$projects = [];
foreach (array_slice($history['projects'], 0, 15, true) as $path => $count) {
    $projects[short_path($path)] = $count;
}

// Tokens générés par jour, sur la période couverte par les transcriptions.
$outputByDay = [];
$dayTicks = [];
if ($tokens['from'] !== '') {
    // Quelques transcriptions isolées peuvent être bien plus anciennes : le graphique se limite à six semaines.
    $chartFrom = max($tokens['from'], date('Y-m-d', strtotime('-41 days')));
    $series = fill_days(array_map(static fn($row) => $row[1], $tokens['days']), $chartFrom, $today);
    $i = 0;
    foreach ($series as $day => $value) {
        $outputByDay[date_fr($day)] = $value;
        if (date('N', strtotime($day)) === '1') {
            $dayTicks[$i] = date('j/n', strtotime($day));
        }
        $i++;
    }
}

$tools = array_slice($tokens['tools'], 0, 15, true);
$skills = [];
foreach ($state['skillUsage'] ?? [] as $name => $usage) {
    $skills[$name] = (int) ($usage['usageCount'] ?? 0);
}
arsort($skills);
$skills = array_slice($skills, 0, 15, true);

$meta = array_filter(['Historique depuis le' => date_fr($history['from'])]);
$kpis = [
    ['Messages envoyés', nf($history['total']), '#activite'],
    ['Sessions', nf($history['sessions']), null],
    ["Jours d'activité", nf($activeDays), null],
    ['Tokens générés', short_number($tokens['total'][1]), '#tokens'],
];
$sectionLinks = ['#activite' => 'Activité', '#projets' => 'Projets', '#tokens' => 'Tokens', '#outils' => 'Outils', '#skills' => 'Skills'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Mon usage de Claude Code</title>
  <link rel="icon" href="data:,">
  <?php head_assets(); ?>
</head>
<body>
<?php page_header(
    'usage',
    'Mon usage de Claude Code',
    'Ce que vous avez demandé à Claude sur cet ordinateur, et ce que cela a consommé.',
    $meta, $kpis, $sectionLinks, false
); ?>

<main class="wrap">
  <section id="activite">
    <h2>Activité</h2>
    <p class="lede">Un carré par jour : plus sa couleur est intense, plus vous avez envoyé de messages à Claude ce jour-là. Survolez un carré pour voir le nombre.</p>
    <?php if ($history['total']): ?>
      <div class="scroll"><?= svg_heatmap($history['days'], $from, $today, 'messages') ?></div>
      <p class="ramp">Moins
        <svg width="77" height="13" aria-hidden="true"><?php for ($level = 0; $level < 5; $level++): ?><rect class="q<?= $level ?>" x="<?= $level * 16 ?>" width="13" height="13" rx="2"/><?php endfor; ?></svg>
        Plus
      </p>
      <h3 class="sub">Selon l'heure de la journée</h3>
      <p class="lede">Messages envoyés à chaque heure, tous jours confondus.</p>
      <div class="scroll"><?= svg_columns($hours, $hourTicks, 'nf', 'Messages par heure de la journée') ?></div>
    <?php else: ?>
      <p class="note">Aucun message dans <code>~/.claude/history.jsonl</code>.</p>
    <?php endif; ?>
  </section>

  <section id="projets">
    <h2>Projets</h2>
    <p class="lede">Les dossiers où vous avez le plus sollicité Claude depuis le début de l'historique.</p>
    <?= $projects ? bar_rows($projects, 'nf', 'Dossier', 'Messages') : '<p class="note">Aucun projet dans l\'historique.</p>' ?>
  </section>

  <section id="tokens">
    <h2>Tokens</h2>
    <?php if ($tokens['from'] !== ''): ?>
    <p class="lede">Claude Code efface les transcriptions anciennes : ces chiffres portent sur celles qui restent sur le disque, la plus ancienne datant du <?= e(date_fr($tokens['from'])) ?>, soit <?= nf($tokens['sessions']) ?> sessions et <?= nf($tokens['total'][4]) ?> réponses.</p>
    <dl class="facts plain">
      <div><dt>Générés par Claude</dt><dd><?= e(short_number($tokens['total'][1])) ?></dd></div>
      <div><dt>Envoyés hors cache</dt><dd><?= e(short_number($tokens['total'][0])) ?></dd></div>
      <div><dt>Écrits dans le cache</dt><dd><?= e(short_number($tokens['total'][2])) ?></dd></div>
      <div><dt>Relus depuis le cache</dt><dd><?= e(short_number($tokens['total'][3])) ?></dd></div>
    </dl>
    <h3 class="sub">Tokens générés par jour, sur six semaines</h3>
    <div class="scroll"><?= svg_columns($outputByDay, $dayTicks, 'short_number', 'Tokens générés par jour') ?></div>
    <p class="note">Les dates sous l'axe sont les lundis.</p>

    <h3 class="sub">Par modèle</h3>
    <div class="scroll">
      <table>
        <thead><tr><th scope="col">Modèle</th><th scope="col" class="num">Réponses</th><th scope="col" class="num">Générés</th><th scope="col" class="num">Envoyés hors cache</th><th scope="col" class="num">Écrits dans le cache</th><th scope="col" class="num">Relus depuis le cache</th></tr></thead>
        <tbody>
        <?php foreach ($tokens['models'] as $model => $row): ?>
          <tr>
            <th scope="row"><code><?= e($model) ?></code></th>
            <td class="num"><?= nf($row[4]) ?></td>
            <td class="num strong"><?= nf($row[1]) ?></td>
            <td class="num"><?= nf($row[0]) ?></td>
            <td class="num"><?= nf($row[2]) ?></td>
            <td class="num"><?= nf($row[3]) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <h3 class="sub">Par projet</h3>
    <div class="scroll">
      <table>
        <thead><tr><th scope="col">Dossier</th><th scope="col" class="num">Sessions</th><th scope="col" class="num">Réponses</th><th scope="col" class="num">Générés</th><th scope="col" class="num">Relus depuis le cache</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($tokens['projects'], 0, 15, true) as $path => $project): ?>
          <tr>
            <th scope="row"><code><?= e(short_path($path)) ?></code></th>
            <td class="num"><?= nf($project['sessions']) ?></td>
            <td class="num"><?= nf($project['tokens'][4]) ?></td>
            <td class="num strong"><?= nf($project['tokens'][1]) ?></td>
            <td class="num"><?= nf($project['tokens'][3]) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="note">Les sessions reprises ou dupliquées peuvent être comptées deux fois. Aucun coût n'est affiché : avec un abonnement, les tokens ne sont pas facturés à l'unité.</p>
    <?php if (!$tokens['cacheWritable']): ?>
      <p class="note"><strong>Cache indisponible.</strong> Le dossier <code>_cache</code> à côté de <code>www</code> n'est pas accessible en écriture : la page relit toutes les transcriptions à chaque affichage.</p>
    <?php endif; ?>
    <?php else: ?>
      <p class="note">Aucune transcription dans <code>~/.claude/projects</code>.</p>
    <?php endif; ?>
  </section>

  <section id="outils">
    <h2>Outils</h2>
    <p class="lede">Les outils que Claude a le plus appelés pendant la période couverte par les transcriptions.</p>
    <?= $tools ? bar_rows($tools, 'nf', 'Outil', 'Appels') : '<p class="note">Aucun appel d\'outil relevé.</p>' ?>
  </section>

  <section id="skills">
    <h2>Skills</h2>
    <p class="lede">Les skills et commandes les plus utilisés depuis l'installation, d'après le compteur de Claude Code.</p>
    <?= $skills ? bar_rows($skills, 'nf', 'Skill', 'Utilisations') : '<p class="note">Aucune utilisation relevée.</p>' ?>
  </section>
</main>

<footer>
  <div class="wrap">Page générée le <?= e(date_fr(date('c'))) ?> à <?= date('H:i') ?> à partir de <code>~/.claude/history.jsonl</code> et des transcriptions de <code>~/.claude/projects</code>. Rechargez-la pour la mettre à jour.</div>
</footer>
</body>
</html>

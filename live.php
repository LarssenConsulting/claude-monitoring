<?php
require __DIR__ . '/_inc/inventory.php';
require __DIR__ . '/_inc/view.php';
require __DIR__ . '/_inc/charts.php';
require __DIR__ . '/_inc/live.php';

// Cette page expose l'activité locale : elle ne répond qu'à cet ordinateur.
if (!is_local_request()) {
    http_response_code(403);
    exit("Accès refusé. Cette page ne répond qu'à cet ordinateur, sur localhost ou sur un nom d'hôte déclaré dans config.local.php (voir le README).");
}
header('Cache-Control: no-store');

$sessions = live_sessions(home_dir() . '/.claude');
$now = time();
$states = ['busy' => 'En activité', 'idle' => 'En attente', 'other' => 'État inconnu'];
$byState = array_count_values(array_column($sessions, 'status'));

$kpis = [
    ['Sessions ouvertes', nf(count($sessions)), '#sessions'],
    ['En activité', nf($byState['busy'] ?? 0), null],
    ['En attente', nf($byState['idle'] ?? 0), null],
];
$sectionLinks = ['#sessions' => 'Sessions'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Mes sessions Claude Code en direct</title>
  <link rel="icon" href="data:,">
  <?php head_assets(); ?>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>" defer></script>
</head>
<body>
<?php page_header(
    'live',
    'Mes sessions Claude Code en direct',
    'Les sessions ouvertes sur cet ordinateur et les outils que Claude y appelle, mis à jour toutes les deux secondes.',
    [], $kpis, $sectionLinks, false
); ?>

<main class="wrap">
  <section id="sessions" data-live>
    <h2>Sessions ouvertes<span class="total"><?= count($sessions) ?></span></h2>
    <p class="lede">Une carte par session, celles en activité d'abord. Seuls les noms des outils sont affichés : ni vos messages, ni les commandes, ni les fichiers.</p>
    <?php if ($sessions): ?>
    <div class="live">
      <?php foreach ($sessions as $session): ?>
      <article class="session <?= e($session['status']) ?>">
        <h3><span class="state"><?= e($states[$session['status']]) ?></span> <code><?= e($session['project'] !== '' ? $session['project'] : 'Dossier inconnu') ?></code></h3>
        <dl class="facts plain">
          <?php if ($session['started']): ?><div><dt>Ouverte depuis</dt><dd><?= e(duration_fr($now - $session['started'])) ?></dd></div><?php endif; ?>
          <?php if ($session['written']): ?><div><dt>Dernière écriture il y a</dt><dd><?= e(duration_fr($now - $session['written'])) ?></dd></div><?php endif; ?>
          <?php if ($session['context']): ?><div><dt>Tokens de contexte</dt><dd><?= e(short_number($session['context'])) ?></dd></div><?php endif; ?>
        </dl>
        <?php $about = array_filter([$session['model'], $session['version'] !== '' ? 'Claude Code ' . $session['version'] : '']); ?>
        <?php if ($about): ?><p class="about"><?= e(implode(' · ', $about)) ?></p><?php endif; ?>
        <h4>Derniers outils appelés</h4>
        <?php if ($session['events']): ?>
        <ol class="events">
          <?php foreach ($session['events'] as $event): ?>
          <li><time><?= $event['time'] ? date(date('Y-m-d', $event['time']) === date('Y-m-d', $now) ? 'H:i:s' : 'j/n H:i', $event['time']) : '' ?></time><span><?= e($event['label']) ?></span><?php if ($event['pending'] && $session['status'] === 'busy'): ?><span class="tag">en cours</span><?php endif; ?></li>
          <?php endforeach; ?>
        </ol>
        <?php else: ?>
        <p class="note">Aucun appel d'outil récent.</p>
        <?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p class="note">Aucune session ouverte. Lancez <code>claude</code> dans un terminal : la session apparaîtra ici.</p>
    <?php endif; ?>
    <p class="note" id="live-error" hidden><strong>Mise à jour interrompue.</strong> Le serveur ne répond pas ; nouvel essai dans quelques secondes.</p>
  </section>
</main>

<footer>
  <div class="wrap">État relevé le <?= e(date_fr(date('c'))) ?> à <span id="live-time"><?= date('H:i:s') ?></span> dans <code>~/.claude/sessions</code> et à la fin des transcriptions de <code>~/.claude/projects</code>. La page se met à jour seule tant que cet onglet est visible.<noscript> JavaScript est désactivé : rechargez la page pour la mettre à jour.</noscript></div>
</footer>
</body>
</html>

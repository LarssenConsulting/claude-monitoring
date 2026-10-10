<?php
require __DIR__ . '/_inc/inventory.php';
require __DIR__ . '/_inc/view.php';

// Cette page expose la configuration locale : elle ne répond qu'à cet ordinateur.
if (!is_local_request()) {
    http_response_code(403);
    exit('Page réservée à cet ordinateur.');
}

$inv = build_inventory();
$sources = $inv['sources'];
$general = $inv['general'];

// Colonnes du tableau de synthèse : type d'élément => [libellé, ancre de la section, groupes par source ?]
$columns = [
    'skills' => ['Skills', 'skills', true],
    'agents' => ['Agents', 'agents', true],
    'commands' => ['Commandes', 'commandes', true],
    'rules' => ['Règles', 'regles', true],
    'hooks' => ['Hooks', 'hooks', false],
    'mcp' => ['Serveurs MCP', 'mcp', false],
    'lsp' => ['LSP', 'mcp', false],
];
$counts = [];
foreach ($columns as $type => $column) {
    $counts[$type] = count_by_source($inv[$type]);
}
$inactiveRules = count(array_filter($inv['rules'], static fn($rule) => !$rule['active']));
$plugins = array_filter($sources, static fn($source) => $source['kind'] !== 'perso' && isset($source['uses']));

$events = [
    'SessionStart' => 'Début de session',
    'UserPromptSubmit' => 'Envoi d\'un message',
    'PreToolUse' => 'Avant un outil',
    'PermissionRequest' => 'Demande de permission',
    'PostToolUse' => 'Après un outil',
    'PostToolUseFailure' => 'Après l\'échec d\'un outil',
    'Notification' => 'Notification',
    'PreCompact' => 'Avant la compaction',
    'Stop' => 'Fin de réponse',
    'SessionEnd' => 'Fin de session',
];
$hooks = $inv['hooks'];
$order = array_flip(array_keys($events));
usort($hooks, static fn($a, $b) => ($order[$a['event']] ?? 99) <=> ($order[$b['event']] ?? 99));

$kinds = ['perso' => '~/.claude', 'plugin' => 'plugin', 'claudeai' => 'claude.ai'];

$meta = array_filter([
    'Version' => $general['version'],
    'Modèle par défaut' => $general['model'],
    'Lancements' => $general['startups'] ?: '',
    'Premier lancement' => date_fr($general['firstStart']),
]);
$kpis = [
    ['Plugins', count($plugins), '#plugins'],
    ['Skills', count($inv['skills']), '#skills'],
    ['Agents', count($inv['agents']), '#agents'],
    ['Commandes', count($inv['commands']), '#commandes'],
    ['Règles', count($inv['rules']), '#regles'],
    ['Hooks', count($inv['hooks']), '#hooks'],
    ['Serveurs MCP', count($inv['mcp']), '#mcp'],
];
$sectionLinks = [
    '#synthese' => 'Synthèse', '#plugins' => 'Plugins', '#skills' => 'Skills', '#agents' => 'Agents',
    '#commandes' => 'Commandes', '#regles' => 'Règles', '#hooks' => 'Hooks', '#mcp' => 'MCP',
    '#instructions' => 'Instructions', '#reglages' => 'Réglages',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Mon installation Claude Code</title>
  <link rel="icon" href="data:,">
  <?php head_assets(); ?>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>" defer></script>
</head>
<body>
<?php page_header(
    'installation',
    'Mon installation Claude Code',
    'Ce qui est installé et utilisable sur cet ordinateur, relu dans ~/.claude à chaque affichage.',
    $meta, $kpis, $sectionLinks, true
); ?>

<main class="wrap">
<?php if (!$inv['readable']): ?>
  <section>
    <h2>Dossier introuvable</h2>
    <p class="lede">Le dossier <code><?= e(home_dir()) ?>/.claude</code> n'est pas lisible par le serveur web. Vérifiez que MAMP tourne sous votre compte utilisateur.</p>
  </section>
<?php endif; ?>

  <section id="synthese" class="matrix">
    <h2>Synthèse</h2>
    <p class="lede">Ce que chaque origine apporte. Cliquez sur un nombre pour voir la liste.</p>
    <div class="scroll">
      <table>
        <thead>
          <tr>
            <th scope="col">Origine</th>
            <?php foreach ($columns as $column): ?><th scope="col" class="num"><?= e($column[0]) ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($sources as $key => $source): ?>
          <tr<?= $source['enabled'] ? '' : ' class="off"' ?>>
            <th scope="row">
              <?= e($source['label']) ?><?php if ($source['version'] !== ''): ?><span class="version"><?= e($source['version']) ?></span><?php endif; ?>
              <span class="tag"><?= e($kinds[$source['kind']]) ?></span>
              <?php if (!$source['enabled']): ?><span class="tag warn">désactivé</span><?php endif; ?>
            </th>
            <?php foreach ($columns as $type => $column):
                $href = '#' . $column[1] . ($column[2] ? '--' . anchor($key) : '');
                echo matrix_cell($counts[$type][$key] ?? 0, $href, $type === 'rules' && $source['kind'] === 'plugin');
            endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th scope="row">Total</th>
            <?php foreach ($columns as $type => $column): ?><td class="num"><?= count($inv[$type]) ?></td><?php endforeach; ?>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php if ($inactiveRules): ?>
      <p class="note"><strong>*</strong> Règles fournies par un plugin : Claude ne les charge que si elles sont copiées dans <code>~/.claude/rules</code> (<?= !empty($counts['rules']['perso']) ? 'une partie y est déjà' : 'ce dossier est vide ou absent pour l\'instant' ?>).</p>
    <?php endif; ?>
    <p class="note">Les skills, agents et commandes intégrés à Claude Code lui-même (<code>/init</code>, <code>/code-review</code>, agents Explore et Plan…) ne figurent pas ici : ils ne sont pas stockés dans <code>~/.claude</code>.</p>
  </section>

  <section id="plugins" data-section>
    <h2>Plugins <span class="total"><?= count($plugins) ?></span></h2>
    <p class="lede">Chaque plugin apporte un lot de skills, d'agents, de commandes ou de hooks.</p>
    <div class="scroll">
      <table>
        <thead>
          <tr><th scope="col">Plugin</th><th scope="col">Ce qu'il apporte</th><th scope="col">Provenance</th><th scope="col">Installé le</th><th scope="col">Mis à jour le</th><th scope="col">Utilisations</th></tr>
        </thead>
        <tbody>
        <?php foreach ($plugins as $source): ?>
          <tr data-search>
            <th scope="row" class="nowrap">
              <?php if (($source['homepage'] ?? '') !== ''): ?><a href="<?= e($source['homepage']) ?>" rel="noopener"><?= e($source['label']) ?></a><?php else: ?><?= e($source['label']) ?><?php endif; ?>
              <?php if ($source['version'] !== ''): ?><span class="version"><?= e($source['version']) ?></span><?php endif; ?>
              <?php if (!$source['enabled']): ?><span class="tag warn">désactivé</span><?php endif; ?>
            </th>
            <td><?= e($source['desc']) ?><?php if (($source['author'] ?? '') !== ''): ?> <span class="soft">Par <?= e($source['author']) ?>.</span><?php endif; ?></td>
            <td class="soft"><?= e($source['origin']) ?></td>
            <td class="soft nowrap"><?= e(date_fr($source['installedAt'] ?? '')) ?></td>
            <td class="soft nowrap"><?= e(date_fr($source['updatedAt'] ?? '')) ?></td>
            <td class="soft"><?= $source['uses'] ?: '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="empty" hidden>Rien ne correspond à cette recherche.</p>
  </section>

  <section id="skills" data-section>
    <h2>Skills <span class="total"><?= count($inv['skills']) ?></span></h2>
    <p class="lede">Des modes d'emploi que Claude charge quand la tâche s'y prête, ou que vous appelez par leur nom avec <code>/nom</code>. Le chiffre vert indique combien de fois vous l'avez utilisé.</p>
    <?php render_groups('skills', $inv['skills'], $sources); ?>
  </section>

  <section id="agents" data-section>
    <h2>Agents <span class="total"><?= count($inv['agents']) ?></span></h2>
    <p class="lede">Des assistants spécialisés auxquels Claude délègue une partie du travail.</p>
    <?php if ($inv['agents']): render_groups('agents', $inv['agents'], $sources); else: ?><p class="note">Aucun agent installé.</p><?php endif; ?>
  </section>

  <section id="commandes" data-section>
    <h2>Commandes <span class="total"><?= count($inv['commands']) ?></span></h2>
    <p class="lede">Des raccourcis à taper dans Claude Code.</p>
    <?php if ($inv['commands']): render_groups('commandes', $inv['commands'], $sources); else: ?><p class="note">Aucune commande installée.</p><?php endif; ?>
  </section>

  <section id="regles" data-section>
    <h2>Règles <span class="total"><?= count($inv['rules']) ?></span></h2>
    <p class="lede">Des consignes permanentes, classées par langage. Seules celles de <code>~/.claude/rules</code> sont lues par Claude ; celles d'un plugin restent en réserve tant qu'elles n'y sont pas copiées.</p>
    <?php if ($inv['rules']): render_groups('regles', $inv['rules'], $sources); else: ?><p class="note">Aucune règle disponible.</p><?php endif; ?>
  </section>

  <section id="hooks" data-section>
    <h2>Hooks <span class="total"><?= count($hooks) ?></span></h2>
    <p class="lede">Des scripts lancés automatiquement à certains moments d'une session.</p>
    <?php if ($hooks): ?>
    <div class="scroll">
      <table>
        <thead>
          <tr><th scope="col">Moment</th><th scope="col">Déclenché par</th><th scope="col">Origine</th><th scope="col">Rôle</th></tr>
        </thead>
        <tbody>
        <?php foreach ($hooks as $hook): ?>
          <tr data-search>
            <th scope="row" class="nowrap"><?= e($events[$hook['event']] ?? $hook['event']) ?> <span class="version"><?= e($hook['event']) ?></span></th>
            <td class="soft"><?= $hook['matcher'] !== '' && $hook['matcher'] !== '.*' ? '<code>' . e($hook['matcher']) . '</code>' : 'toujours' ?></td>
            <td class="nowrap"><?= e($sources[$hook['source']]['label'] ?? $hook['source']) ?></td>
            <td><code><?= e($hook['name']) ?></code><?php if ($hook['desc'] !== ''): ?><br><span class="soft"><?= e($hook['desc']) ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="empty" hidden>Rien ne correspond à cette recherche.</p>
    <?php else: ?><p class="note">Aucun hook actif.</p><?php endif; ?>
  </section>

  <section id="mcp" data-section>
    <h2>Serveurs MCP <span class="total"><?= count($inv['mcp']) ?></span></h2>
    <p class="lede">Les connexions qui donnent à Claude accès à des outils extérieurs : navigateur, messagerie, gestion de tâches…</p>
    <div class="scroll">
      <table>
        <thead>
          <tr><th scope="col">Serveur</th><th scope="col">Origine</th><th scope="col">Portée</th><th scope="col">Lancement ou adresse</th></tr>
        </thead>
        <tbody>
        <?php foreach ($inv['mcp'] as $server): ?>
          <tr data-search>
            <th scope="row" class="nowrap"><?= e($server['name']) ?><?php if ($server['auth']): ?><span class="tag warn">connexion à faire</span><?php endif; ?></th>
            <td class="nowrap"><?= e($sources[$server['source']]['label'] ?? $server['source']) ?></td>
            <td class="soft"><?= e($server['scope']) ?></td>
            <td class="soft"><?= $server['how'] !== '' ? '<code>' . e($server['how']) . '</code>' : '' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="empty" hidden>Rien ne correspond à cette recherche.</p>
    <?php if ($inv['lsp']): ?>
    <h3 class="sub">Serveurs de langage (LSP)</h3>
    <div class="scroll">
      <table>
        <thead><tr><th scope="col">Serveur</th><th scope="col">Origine</th><th scope="col">Langages</th><th scope="col">Commande</th></tr></thead>
        <tbody>
        <?php foreach ($inv['lsp'] as $lsp): ?>
          <tr data-search>
            <th scope="row"><?= e($lsp['name']) ?></th>
            <td><?= e($sources[$lsp['source']]['label'] ?? $lsp['source']) ?></td>
            <td class="soft"><?= e($lsp['languages']) ?></td>
            <td class="soft"><code><?= e($lsp['how']) ?></code></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <section id="instructions" data-section>
    <h2>Instructions et mémoire</h2>
    <p class="lede">Ce que Claude lit avant de travailler : vos consignes globales, puis le <code>CLAUDE.md</code> et les souvenirs de chaque projet.</p>
    <h3 class="sub">Consignes globales <span class="version">~/.claude/CLAUDE.md</span></h3>
    <?php if ($inv['globalInstructions'] !== ''): ?>
      <pre class="file"><?= e(trim($inv['globalInstructions'])) ?></pre>
    <?php else: ?>
      <p class="note">Pas de fichier <code>~/.claude/CLAUDE.md</code> : aucune consigne ne s'applique à tous vos projets.</p>
    <?php endif; ?>
    <h3 class="sub">Par projet <span class="version"><?= count($inv['projects']) ?></span></h3>
    <div class="scroll">
      <table>
        <thead>
          <tr><th scope="col">Projet</th><th scope="col">CLAUDE.md</th><th scope="col">Souvenirs</th><th scope="col">Serveurs MCP du projet</th><th scope="col">Éléments propres au projet</th></tr>
        </thead>
        <tbody>
        <?php foreach ($inv['projects'] as $project): ?>
          <tr data-search>
            <th scope="row"><code><?= e($project['path']) ?></code></th>
            <td><?= $project['claudeMd'] ? 'oui' : '<span class="soft">–</span>' ?></td>
            <td><?= $project['memories'] ?: '<span class="soft">–</span>' ?></td>
            <td class="soft"><?= e(implode(', ', $project['mcp'])) ?></td>
            <td class="soft"><?php
                $parts = [];
                foreach ($project['local'] as $label => $count) {
                    $parts[] = $count . ' ' . $label;
                }
                echo e(implode(', ', $parts));
            ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="empty" hidden>Rien ne correspond à cette recherche.</p>
  </section>

  <section id="reglages" data-section>
    <h2>Réglages</h2>
    <p class="lede">Le contenu de <code>~/.claude/settings.json</code> et les dépôts d'où viennent vos plugins.</p>
    <dl class="settings">
      <?php if ($general['installMethod'] !== ''): ?><div><dt>Type d'installation</dt><dd><?= e($general['installMethod']) ?></dd></div><?php endif; ?>
      <?php foreach ($general['settings'] as $name => $value): ?>
        <div><dt><code><?= e($name) ?></code></dt><dd><?= is_bool($value) ? ($value ? 'oui' : 'non') : e($value) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <h3 class="sub">Sources de plugins <span class="version"><?= count($inv['marketplaces']) ?></span></h3>
    <div class="scroll">
      <table>
        <thead><tr><th scope="col">Nom</th><th scope="col">Dépôt</th><th scope="col">Dernière synchronisation</th></tr></thead>
        <tbody>
        <?php foreach ($inv['marketplaces'] as $market): ?>
          <tr data-search>
            <th scope="row"><?= e($market['name']) ?></th>
            <td class="soft"><code><?= e($market['source']) ?></code></td>
            <td class="soft"><?= e(date_fr($market['updatedAt'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<footer>
  <div class="wrap">Page générée le <?= e(date_fr(date('c'))) ?> à <?= date('H:i') ?> à partir de <code>~/.claude</code> et <code>~/.claude.json</code>. Rechargez-la pour la mettre à jour.</div>
</footer>
</body>
</html>

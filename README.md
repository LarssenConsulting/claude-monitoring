# Claude Monitoring

Trois pages web locales pour voir, d'un coup d'œil, ce que contient votre installation de [Claude Code](https://claude.com/claude-code), comment vous vous en servez et ce qui s'y passe en ce moment.

Les pages lisent directement le dossier `~/.claude` de votre ordinateur à chaque affichage. Rien n'est envoyé ailleurs, et le dépôt ne contient aucune donnée : chacun voit sa propre installation.

## Ce que montrent les pages

**Installation** (`/`)

- un tableau de synthèse : pour chaque origine (vous, chaque plugin, votre compte claude.ai), le nombre de skills, d'agents, de commandes, de règles, de hooks et de serveurs MCP ;
- le détail de chaque élément, avec sa description et le nombre de fois où vous l'avez utilisé ;
- vos consignes globales, puis le `CLAUDE.md`, les souvenirs et les serveurs MCP de chaque projet ;
- vos réglages et les dépôts d'où viennent vos plugins ;
- une recherche qui filtre toute la page (touche `/`).

**Usage** (`/usage.php`)

- un calendrier des messages envoyés par jour, et leur répartition par heure ;
- les projets où vous sollicitez le plus Claude ;
- les tokens par jour, par modèle et par projet ;
- les outils et les skills les plus utilisés.

**Live** (`/live.php`)

- une carte par session Claude Code ouverte sur l'ordinateur, avec son état (en activité ou en attente), son dossier, sa durée, son modèle et la taille de son contexte ;
- les derniers outils que Claude y a appelés, avec l'heure, et celui qui est en cours ;
- une mise à jour automatique toutes les deux secondes, suspendue quand l'onglet n'est pas visible.

## Prérequis

- PHP 8.0 ou plus récent, sans extension particulière ;
- macOS ou Linux (non testé sous Windows) ;
- un serveur web qui tourne sous votre compte utilisateur, pour pouvoir lire `~/.claude`.

Aucune dépendance à installer, aucune étape de build.

## Installation

```bash
git clone https://github.com/LarssenConsulting/claude-monitoring.git
cd claude-monitoring
php -S 127.0.0.1:8000
```

Ouvrez ensuite http://127.0.0.1:8000.

Vous pouvez aussi faire pointer un hôte virtuel Apache ou nginx (MAMP, par exemple) sur le dossier du dépôt. Dans ce cas, les pages répondent par une erreur 403 tant que le nom de cet hôte n'est pas déclaré. Copiez le fichier d'exemple fourni :

```bash
cp config.local.example.php config.local.php
```

Puis remplacez `mon-site.test` par le nom de votre hôte dans `config.local.php` :

```php
<?php
return [
    'hosts' => ['mon-site.test'],
];
```

Le nom doit être écrit en entier, sans port ni joker (`*.test` n'est pas accepté). Vous pouvez en indiquer plusieurs. `config.local.php` est ignoré par git : il reste sur votre ordinateur.

Avec `php -S 127.0.0.1:8000`, ce fichier est inutile.

## Confidentialité

- Les pages ne répondent qu'aux requêtes venant de l'ordinateur lui-même (`127.0.0.1` ou `::1`) et adressées à un nom d'hôte autorisé : `localhost`, `127.0.0.1`, `[::1]`, ou un nom déclaré dans `config.local.php`. Toute autre requête reçoit une erreur 403. Cette liste fermée empêche un site web malveillant de lire les pages à travers votre navigateur. Derrière un proxy inverse, cette protection ne suffit plus : ne publiez pas ces pages sur un réseau.
- Les variables d'environnement des serveurs MCP et les informations de votre compte ne sont jamais affichées.
- Pour un serveur MCP, seuls le programme et le paquet lancés sont affichés (`npx nom-du-paquet`), ou le nom de domaine pour une adresse : jamais les options, les autres arguments, le chemin ni les paramètres d'une URL. Pour un hook, seul le nom du script ou du programme apparaît. Les réglages en texte libre sont indiqués comme « définis » sans leur valeur. Les liens vers les dépôts de plugins sont débarrassés de leurs éventuels identifiants.
- La page Live n'affiche d'un appel d'outil que son nom (`Bash`, `Edit`…), suivi du skill ou du type d'agent lancé : jamais vos messages, les réponses de Claude, les commandes, les fichiers touchés ni le titre de la session.
- La page d'installation affiche en entier votre fichier `~/.claude/CLAUDE.md` : n'y mettez pas de secret.
- Les polices sont chargées depuis Google Fonts : c'est la seule requête vers l'extérieur.

## À savoir

- **Tokens** : ils sont calculés à partir des transcriptions de `~/.claude/projects`, que Claude Code efface avec le temps. La période couverte est donc limitée, souvent à quelques semaines. Les sessions reprises ou dupliquées peuvent être comptées deux fois.
- **Coûts** : aucun montant n'est affiché.
- **Éléments intégrés** : les skills, agents et commandes fournis par Claude Code lui-même (`/init`, Explore, Plan…) n'apparaissent pas, car ils ne sont pas stockés dans `~/.claude`.
- **Sessions en direct** : la page Live s'appuie sur `~/.claude/sessions`, un dossier interne de Claude Code dont le format n'est pas documenté et peut changer d'une version à l'autre. Elle n'a été vérifiée qu'avec des sessions lancées en ligne de commande, et ne détaille pas l'activité propre des sous-agents.
- **Cache** : pour ne pas relire toutes les transcriptions à chaque affichage, la page d'usage enregistre ses calculs dans un dossier `_cache/` créé **à côté** du dossier du dépôt, donc hors de la racine web. Vous pouvez le supprimer : il se recrée. S'il ne peut pas être créé, la page fonctionne quand même, plus lentement.

## Organisation du code

```
index.php            Page d'installation
usage.php            Page d'usage
live.php             Page des sessions en direct
_inc/inventory.php   Lecture de ~/.claude : plugins, skills, agents, hooks, serveurs MCP…
_inc/usage.php       Historique des messages et tokens des transcriptions
_inc/live.php        Sessions ouvertes et derniers outils appelés
_inc/charts.php      Graphiques SVG générés côté serveur
_inc/view.php        En-tête commun et fonctions d'affichage
assets/css/style.css Styles, thème clair et sombre
assets/js/app.js     Recherche dans la page, mise à jour de la page Live
```

Le fichier `CLAUDE.md` décrit le fonctionnement interne pour qui veut modifier le code avec Claude Code.

## Licence

Code publié sous licence MIT : voir le fichier [LICENSE](LICENSE).

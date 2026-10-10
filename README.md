# Claude Monitoring

Deux pages web locales pour voir, d'un coup d'œil, ce que contient votre installation de [Claude Code](https://claude.com/claude-code) et comment vous vous en servez.

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

Vous pouvez aussi faire pointer un hôte virtuel Apache ou nginx (MAMP, par exemple) sur le dossier du dépôt.

## Confidentialité

- Les pages ne répondent qu'aux requêtes venant de l'ordinateur lui-même (`127.0.0.1` ou `::1`) ; toute autre adresse reçoit une erreur 403. Derrière un proxy inverse, cette protection ne suffit plus : ne publiez pas ces pages sur un réseau.
- Les variables d'environnement des serveurs MCP et les informations de votre compte ne sont jamais affichées.
- Les polices sont chargées depuis Google Fonts : c'est la seule requête vers l'extérieur.

## À savoir

- **Tokens** : ils sont calculés à partir des transcriptions de `~/.claude/projects`, que Claude Code efface avec le temps. La période couverte est donc limitée, souvent à quelques semaines. Les sessions reprises ou dupliquées peuvent être comptées deux fois.
- **Coûts** : aucun montant n'est affiché.
- **Éléments intégrés** : les skills, agents et commandes fournis par Claude Code lui-même (`/init`, Explore, Plan…) n'apparaissent pas, car ils ne sont pas stockés dans `~/.claude`.
- **Cache** : pour ne pas relire toutes les transcriptions à chaque affichage, la page d'usage enregistre ses calculs dans un dossier `_cache/` créé **à côté** du dossier du dépôt, donc hors de la racine web. Vous pouvez le supprimer : il se recrée. S'il ne peut pas être créé, la page fonctionne quand même, plus lentement.

## Organisation du code

```
index.php            Page d'installation
usage.php            Page d'usage
_inc/inventory.php   Lecture de ~/.claude : plugins, skills, agents, hooks, serveurs MCP…
_inc/usage.php       Historique des messages et tokens des transcriptions
_inc/charts.php      Graphiques SVG générés côté serveur
_inc/view.php        En-tête commun et fonctions d'affichage
assets/css/style.css Styles, thème clair et sombre
assets/js/app.js     Recherche dans la page
```

Le fichier `CLAUDE.md` décrit le fonctionnement interne pour qui veut modifier le code avec Claude Code.

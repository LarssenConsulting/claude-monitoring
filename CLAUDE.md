# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## À quoi sert ce projet

**Claude Monitoring** décrit l'installation Claude Code de l'ordinateur sur lequel il tourne, en trois pages qui relisent `~/.claude` et `~/.claude.json` à chaque affichage :

- `/` — l'inventaire : plugins, skills, agents, commandes, règles, hooks, serveurs MCP, instructions et mémoire par projet, réglages ;
- `/usage.php` — l'usage : messages par jour et par heure, projets, tokens par jour, modèle et projet, outils et skills les plus utilisés ;
- `/live.php` — les sessions ouvertes : état, dossier, modèle, contexte et derniers outils appelés, mis à jour toutes les deux secondes.

PHP 8 sans framework, sans dépendance, sans étape de build. Pas de lint ni de tests pour l'instant.

**Le dépôt est public et ne contient aucune donnée** : tout ce qui s'affiche est lu sur le disque au moment de l'affichage. Ne jamais écrire dans le code, les commentaires ou la documentation un nom de projet, un chemin personnel ou une valeur lue dans `~/.claude`.

## Commandes

```bash
php -S 127.0.0.1:8000    # serveur local, depuis la racine du dépôt
php -l fichier.php       # vérification de syntaxe
```

Le site peut aussi être servi par un hôte virtuel Apache ou nginx pointant sur la racine du dépôt.

## Organisation

```
index.php            Page d'inventaire : prépare les compteurs puis affiche chaque section
usage.php            Page d'usage
live.php             Page des sessions en direct
_inc/inventory.php   Lecture du disque : build_inventory() renvoie tout l'inventaire dans un tableau
_inc/usage.php       Historique des messages et tokens des transcriptions (avec cache)
_inc/live.php        Sessions ouvertes et derniers outils appelés, lus à la fin des transcriptions
_inc/charts.php      Graphiques SVG générés côté serveur, formats de nombres
_inc/view.php        Haut de page commun, échappement, dates et durées en français, listes groupées
assets/css/style.css Styles ; tokens light-dark() (thème clair et sombre)
assets/js/app.js     Recherche dans la page d'inventaire, ouverture du groupe visé par un lien, mise à jour de la page Live
```

Le cache de la page d'usage est écrit dans `../_cache/usage.json`, c'est-à-dire à côté du dossier du dépôt, hors de la racine web. Il est recréé automatiquement et peut être supprimé sans risque.

## Fonctionnement

- `build_inventory()` range chaque élément sous une **source** : `perso` (`~/.claude`), un plugin (`nom@marketplace`, lu dans `plugins/installed_plugins.json`), un plugin synchronisé depuis claude.ai (`nom@claude.ai`) ou `claudeai` (skills et connecteurs du compte). Le tableau de synthèse en haut de page croise ces sources avec les types d'éléments ; ajouter un type = l'alimenter dans `build_inventory()` puis l'ajouter à `$columns` dans `index.php`.
- Un plugin peut déplacer ses dossiers `skills`, `agents`, `commands` dans son `plugin.json` (chemin unique ou liste) : passer par la fonction `$dirs` plutôt que supposer le dossier par défaut.
- **Usage** : les messages viennent de `~/.claude/history.jsonl` ; les tokens et les appels d'outils viennent des transcriptions de `~/.claude/projects/**/*.jsonl`, que Claude Code efface avec le temps. Ces fichiers peuvent peser plusieurs centaines de Mo : `usage_transcripts()` ne relit que ceux dont la date ou la taille a changé et garde les agrégats dans le cache. Une réponse est écrite sur plusieurs lignes d'une transcription : toujours dédoublonner par `message.id`, sinon les tokens sont comptés plusieurs fois.
- **Live** : `live_sessions()` lit `~/.claude/sessions/*.json` (un fichier par session ouverte, format interne à Claude Code) et ne garde que les processus vivants ; `live_activity()` ne lit que la fin de chaque transcription (`LIVE_TAIL_BYTES`), jamais le fichier entier, car la page est redemandée toutes les deux secondes. Il n'y a pas de point d'entrée JSON : `app.js` redemande `live.php` et remplace les blocs `.kpis`, `[data-live]` et `#live-time`, pour que PHP reste le seul à fabriquer l'affichage. D'un appel d'outil, seul `live_tool_label()` décide de ce qui sort : le nom de l'outil, plus le skill ou le type d'agent. Ne jamais afficher un message, une commande, un chemin de fichier ni le titre de la session.
- **Interface** : design system issu du skill `ui-ux-pro-max` (palette ardoise sombre avec accent vert `#22C55E`, Fira Sans et Fira Code, cartes en verre dépoli, espacement dense). Tout passe par les tokens déclarés en tête de `style.css` (`--color-*`, `--space-*`, `--q0` à `--q4`) : pas de couleur en dur dans les composants, et chaque token donne sa valeur claire et sombre avec `light-dark()`. Le haut de page (barre, titre, chiffres clés) est commun aux trois pages : `page_header()` dans `_inc/view.php`.
- **Graphiques** : SVG produits par `_inc/charts.php`, colorés par des classes CSS, une seule série et une seule teinte par graphique.

## Règles de code

- **Confidentialité** : les pages ne répondent qu'aux requêtes locales adressées à un nom d'hôte local (`is_local_request()`, qui vérifie l'adresse du client et l'en-tête `Host` contre le DNS rebinding). Les noms d'hôte acceptés forment une liste fermée, comparée à l'identique : `localhost`, l'adresse de bouclage et ceux de `config.local.php` (fichier hors dépôt, `['hosts' => [...]]`). Ne jamais accepter un suffixe générique comme `.test` ou `.local`. Elles n'affichent jamais les variables `env` des serveurs MCP ni les informations du compte. Conserver ces garde-fous.
- **Liste blanche, pas de détection de secrets** : toute valeur issue d'une commande, d'une adresse ou d'un réglage passe par `safe_command()`, `safe_url()`, `public_link()` (liens cliquables), `hook_script()` ou `safe_settings()`, qui ne laissent passer que des formes reconnues (programme et nom de paquet, nom de domaine, nom de script, booléens et nombres). Ne jamais afficher une commande, une URL ou une chaîne de configuration brute, même « nettoyée ».
- Les manifestes de plugins ne sont pas des sources sûres : un lien n'est cliquable que s'il est en `http(s)`, et un chemin déclaré par un plugin doit rester à l'intérieur de son dossier.
- Toute valeur lue sur le disque passe par `e()` avant affichage.
- Pas de CSS ni de JS en ligne dans les pages (`<style>`, `style=""`, `<script>` avec du code) : uniquement `assets/css/style.css` et `assets/js/app.js`. Les graphiques SVG utilisent des attributs de géométrie et des classes, jamais `style`.
- Les textes de l'interface et la documentation sont en français, avec les accents.
- Accessibilité : contraste AA, focus visible, cibles tactiles de 44 px sur mobile, `prefers-reduced-motion` respecté.

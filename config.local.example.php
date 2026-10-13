<?php

/**
 * Exemple de configuration locale. Copier ce fichier en config.local.php (ignoré par git), au même endroit.
 *
 * Utile seulement si les pages sont servies sous un autre nom que localhost ou 127.0.0.1,
 * par exemple un hôte virtuel Apache ou nginx : sans cela, elles répondent par une erreur 403.
 */
return [
    // Noms d'hôte autorisés, comparés à l'identique : nom complet, sans port ni joker (pas de « *.test »).
    'hosts' => ['mon-site.test'],
];

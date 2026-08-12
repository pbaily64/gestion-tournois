<?php

declare(strict_types=1);

/**
 * Point d'entree unique du module Tournois.
 * Toutes les URL du module aboutissent ici (voir la configuration Nginx).
 *
 * Cette page de controle est provisoire : elle verifie que
 * l'environnement est complet. A remplacer par le routeur des
 * l'etape 1 du plan de mise en oeuvre.
 */

$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    exit('Dependances absentes : lancez composer install.');
}

require $autoload;
require __DIR__ . '/config/config.php';

header('Content-Type: text/html; charset=utf-8');

$controles = [
    'PHP 8.2 ou superieur' => version_compare(PHP_VERSION, '8.2', '>='),
    'Extension pdo_mysql'  => extension_loaded('pdo_mysql'),
    'Extension mbstring'   => extension_loaded('mbstring'),
    'Extension gd'         => extension_loaded('gd'),
    'Extension intl'       => extension_loaded('intl'),
];

$erreurBase = null;

try {
    $version = db()->query('SELECT VERSION()')->fetchColumn();
    $controles['Connexion MariaDB'] = true;
} catch (Throwable $e) {
    $controles['Connexion MariaDB'] = false;
    $version = null;
    $erreurBase = $e->getMessage();
}

$toutOk = !in_array(false, $controles, true);

?><!DOCTYPE html>
<html lang="fr">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Module Tournois &mdash; controle d'environnement</title>
<style>
    body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 2rem auto; padding: 0 1rem; }
    h1 { font-size: 1.3rem; }
    li { margin: .3rem 0; }
    .ok { color: #157347; font-weight: 600; }
    .ko { color: #b02a37; font-weight: 600; }
    .bilan { padding: .8rem 1rem; border-radius: .4rem; margin: 1.2rem 0; }
    .bilan.ok { background: #d1e7dd; }
    .bilan.ko { background: #f8d7da; }
    pre { background: #f4f4f4; padding: .6rem; overflow-x: auto; font-size: .85rem; }
</style>

<h1>Royal Mickey Club Falisolle &mdash; module Tournois</h1>

<div class="bilan <?= $toutOk ? 'ok' : 'ko' ?>">
    <?= $toutOk
        ? 'Environnement complet : le developpement peut commencer.'
        : 'Environnement incomplet : voir les points en echec ci-dessous.' ?>
</div>

<ul>
<?php foreach ($controles as $libelle => $ok): ?>
    <li><?= htmlspecialchars($libelle) ?> :
        <span class="<?= $ok ? 'ok' : 'ko' ?>"><?= $ok ? 'OK' : 'ECHEC' ?></span></li>
<?php endforeach; ?>
</ul>

<?php if ($erreurBase !== null): ?>
    <pre><?= htmlspecialchars($erreurBase) ?></pre>
<?php endif; ?>

<p>
    PHP <?= PHP_VERSION ?><?= $version ? ' &mdash; MariaDB ' . htmlspecialchars((string) $version) : '' ?><br>
    URL de base : <code><?= htmlspecialchars(BASE_URL) ?></code><br>
    Mode debug : <code><?= DEBUG ? 'actif' : 'inactif' ?></code>
</p>

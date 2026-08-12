<?php

declare(strict_types=1);

/**
 * Configuration du module Tournois.
 *
 * Ce fichier est versionne et ne contient AUCUN identifiant ni aucune
 * valeur propre a un environnement. Tout ce qui distingue le
 * developpement de la production vit dans config.local.php,
 * ignore par Git.
 */

/** Repertoire racine du module sur le disque. */
define('BASE_PATH', dirname(__DIR__));

$fichierLocal = __DIR__ . '/config.local.php';

if (!is_file($fichierLocal)) {
    http_response_code(500);
    exit(
        'Configuration absente : copiez config/config.local.php.exemple '
        . 'vers config/config.local.php et renseignez-le.'
    );
}

/**
 * @var array{
 *     base_url:string, db:array{host:string,name:string,user:string,pass:string,port?:int},
 *     prefix?:string, debug?:bool
 * } $CONFIG
 */
$CONFIG = require $fichierLocal;

/**
 * Segment d'URL sous lequel le module est publie, sans slash final.
 * Vaut /gestion_tournois_dev en developpement, /gestion_tournois en
 * production : c'est la SEULE difference entre les deux environnements.
 */
define('BASE_URL', rtrim($CONFIG['base_url'], '/'));

define('DEBUG', (bool) ($CONFIG['debug'] ?? false));

// --- Affichage des erreurs -------------------------------------------

error_reporting(E_ALL);
ini_set('display_errors', DEBUG ? '1' : '0');
ini_set('log_errors', '1');

// --- Acces a la base de donnees --------------------------------------

/**
 * Connexion PDO partagee, ouverte a la premiere utilisation.
 *
 * ATTENTION : la table `users` du site n'est jamais jointe aux tables
 * du module (dossier de conception, section 3.1). Le module vit dans
 * sa propre base.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    global $CONFIG;
    $db = $CONFIG['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'],
        $db['port'] ?? 3306,
        $db['name']
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/** Nom reel d'une table, prefixe compris (prefixe vide si base dediee). */
function table(string $nom): string
{
    global $CONFIG;

    return ($CONFIG['prefix'] ?? '') . $nom;
}

/** URL absolue d'une ressource du module. */
function url(string $chemin = ''): string
{
    return BASE_URL . '/' . ltrim($chemin, '/');
}

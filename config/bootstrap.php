<?php

declare(strict_types=1);

/**
 * Amorcage du module Tournois.
 *
 * A inclure en tete de CHAQUE page. Il enchaine, dans cet ordre :
 *   1. la configuration du module -> db(), table(), url(), BASE_URL
 *   2. la configuration du site   -> session, SITE_NAME, Database
 *   3. les helpers du site        -> e(), formatDate(), isLoggedIn(), hasRole()
 *
 * Aucun nom n'entre en collision entre les deux : le site definit
 * SITE_NAME, SITE_URL, Database, e(), formatDate() ; le module definit
 * BASE_PATH, BASE_URL, DEBUG, db(), table(), url().
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    exit('Dependances absentes : lancez composer install.');
}

require $autoload;

// --- Configuration du module (definit $CONFIG, BASE_URL, db(), ...) ---
require __DIR__ . '/config.php';

// --- Pont vers le site du club ---------------------------------------

$cheminSite = rtrim($CONFIG['site_path'] ?? dirname(BASE_PATH), '/');

if (!is_file($cheminSite . '/config/config.php')) {
    http_response_code(500);
    exit('Site introuvable : verifiez site_path dans config.local.php.');
}

// Le config.php du site appelle session_start() : la session est donc
// partagee avec le site, meme domaine et meme PHP-FPM.
require_once $cheminSite . '/config/config.php';
require_once $cheminSite . '/config/database.php';
require_once $cheminSite . '/includes/auth.php';

/** Chemin absolu du site, pour inclure header.php et footer.php. */
define('SITE_PATH', $cheminSite);

// --- Controle d'acces -------------------------------------------------

/**
 * Roles autorises a gerer les tournois.
 *
 * @return list<string>
 */
function rolesGestion(): array
{
    global $CONFIG;

    return $CONFIG['roles_gestion'] ?? ['admin'];
}

/** L'utilisateur courant peut-il encoder et gerer les tournois ? */
function peutGerer(): bool
{
    return isLoggedIn() && hasAnyRole(rolesGestion());
}

/**
 * Exige un acces gestion. A appeler en tete de chaque page du module.
 *
 * Le lien de menu masque ne protege rien : le repertoire est servi
 * directement par Nginx, n'importe qui peut taper l'URL.
 */
function exigerAccesGestion(): void
{
    if (!isLoggedIn()) {
        header('Location: /login.php');
        exit;
    }

    if (!peutGerer()) {
        header('Location: /index.php');
        exit;
    }
}

// --- Protection CSRF --------------------------------------------------

/** Jeton de la session, cree au premier appel. */
function jetonCsrf(): string
{
    if (empty($_SESSION['csrf_tournois'])) {
        $_SESSION['csrf_tournois'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_tournois'];
}

/** Champ cache a placer dans chaque formulaire. */
function champCsrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(jetonCsrf()) . '">';
}

/** Verifie le jeton d'une requete POST ; interrompt si invalide. */
function verifierCsrf(): void
{
    $recu = $_POST['csrf'] ?? '';

    if (!is_string($recu) || !hash_equals(jetonCsrf(), $recu)) {
        http_response_code(419);
        exit('Jeton de securite invalide. Rechargez la page et recommencez.');
    }
}

// --- Messages d'une page a l'autre ------------------------------------

/** Depose un message affiche apres redirection. */
function message(string $texte, string $type = 'success'): void
{
    $_SESSION['flash_tournois'][] = ['texte' => $texte, 'type' => $type];
}

/**
 * Recupere et vide les messages en attente.
 *
 * @return list<array{texte:string,type:string}>
 */
function messages(): array
{
    $liste = $_SESSION['flash_tournois'] ?? [];
    unset($_SESSION['flash_tournois']);

    return $liste;
}

// --- Mise en page -----------------------------------------------------

/**
 * Ouvre la page avec l'en-tete du site.
 *
 * @param list<string> $css feuilles de style supplementaires
 */
function ouvrirPage(string $titre, array $css = []): void
{
    global $pageTitle, $additionalCSS;

    $pageTitle     = $titre;
    $additionalCSS = array_merge([ressource('assets/css/tournois.css')], $css);

    require SITE_PATH . '/includes/header.php';

    foreach (messages() as $m) {
        printf(
            '<div class="alert alert-%s alert-dismissible fade show" role="alert">%s'
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>',
            e($m['type']),
            e($m['texte'])
        );
    }
}

/**
 * URL d'un fichier du module, suffixee de sa date de modification.
 *
 * Sans ce jeton, le navigateur conserve indefiniment une feuille de
 * style ou un script deja telecharge : l'URL n'ayant pas change, il ne
 * redemande rien. C'est la cause classique d'une mise en page qui
 * « ne se met pas a jour » apres un deploiement.
 */
function ressource(string $chemin): string
{
    $absolu  = BASE_PATH . '/' . ltrim($chemin, '/');
    $version = is_file($absolu) ? filemtime($absolu) : time();

    return url($chemin) . '?v=' . $version;
}

/**
 * Ferme la page avec le pied de page du site.
 *
 * @param list<string> $js scripts supplementaires
 */
function fermerPage(array $js = []): void
{
    global $additionalJS;

    $additionalJS = $js;

    require SITE_PATH . '/includes/footer.php';
}

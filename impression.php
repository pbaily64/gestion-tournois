<?php

declare(strict_types=1);

/**
 * Documents a imprimer.
 *
 * Page autonome : ni en-tete ni menu du site, seulement ce qui doit
 * sortir sur le papier. L'impression se declenche par le navigateur,
 * qui sait aussi produire un PDF — c'est la voie la plus fidele, ce
 * qui est affiche etant exactement ce qui sortira.
 *
 * Gabarits repris du classeur :
 *   - « General »  : les poules disposees par trois en largeur
 *   - « Template » : le bordereau de match, une ligne par joueur,
 *                    ici etendu jusqu'a cinq sets selon le format
 */

require __DIR__ . '/config/bootstrap.php';

exigerAccesGestion();

use RMCF\Tournois\Domain\FormatMatch;
use RMCF\Tournois\Repository\PhaseRepository;
use RMCF\Tournois\Repository\PouleRepository;
use RMCF\Tournois\Repository\TableauRepository;

$pdo       = db();
$repoPhase = new PhaseRepository($pdo);
$repoPoule = new PouleRepository($pdo);
$repoTableau = new TableauRepository($pdo);

$phaseId = (int) ($_GET['phase'] ?? 0);
$phase   = $phaseId > 0 ? $repoPhase->phase($phaseId) : null;

if ($phase === null) {
    http_response_code(404);
    exit('Phase inconnue.');
}

$tournoi = $repoPhase->tournoi((int) $phase['tournoi_id']);
$format  = FormatMatch::tryFrom((string) ($phase['format_match'] ?? ''))
    ?? FormatMatch::TroisSetsSecs;

$poules   = $repoPoule->poules($phaseId);
$doc      = (string) ($_GET['doc'] ?? 'poules');
$filtre   = (string) ($_GET['poule'] ?? '');
$contexte = (string) ($_GET['contexte'] ?? 'tableau_final');

// Les tableaux existent-ils, et lesquels ?
$tableaux = [];

if ($repoTableau->existent($phaseId)) {
    if ($repoTableau->parTour($phaseId, 'barrage') !== []) {
        $tableaux['barrage'] = 'Barrage';
    }

    $tableaux['tableau_final'] = 'Tableau final';

    if ($repoTableau->parTour($phaseId, 'consolation') !== []) {
        $tableaux['consolation'] = 'Consolante';
    }
}

$titre = match ($doc) {
    'matchs'         => 'Feuilles de match des poules',
    'tableau'        => $tableaux[$contexte] ?? 'Tableau',
    'matchs_tableau' => 'Feuilles de match — ' . ($tableaux[$contexte] ?? ''),
    default          => 'Tableau des poules',
};

// Le tableau en arbre demande la largeur d'une feuille couchee.
$paysage = $doc === 'tableau';

$entete = trim(
    ($tournoi['libelle'] ?? '')
    . ' — ' . ($phase['libelle'] ?? 'Phase')
    . ($phase['date_phase'] ? ' — ' . formatDate($phase['date_phase']) : '')
);

?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?php echo e($titre . ' — ' . $entete); ?></title>
<link rel="stylesheet" href="<?php echo e(ressource('assets/css/impression.css')); ?>">
<?php if ($paysage): ?>
    <style>@page { size: A4 landscape; margin: 8mm; }</style>
<?php endif; ?>
</head>
<body>

<!-- Barre d'action, absente du papier -->
<div class="barre">
    <a href="<?php echo e(url('phase.php?phase=' . $phaseId)); ?>">&larr; Retour à la phase</a>

    <span class="liens">
        <a href="<?php echo e(url('impression.php?phase=' . $phaseId . '&doc=poules')); ?>"
           class="<?php echo $doc === 'poules' ? 'actif' : ''; ?>">Tableau des poules</a>
        <a href="<?php echo e(url('impression.php?phase=' . $phaseId . '&doc=matchs')); ?>"
           class="<?php echo $doc === 'matchs' && $filtre === '' ? 'actif' : ''; ?>">Toutes les feuilles de match</a>

        <?php foreach ($poules as $p): ?>
            <a href="<?php echo e(url('impression.php?phase=' . $phaseId . '&doc=matchs&poule=' . $p['lettre'])); ?>"
               class="<?php echo $doc === 'matchs' && $filtre === (string) $p['lettre'] ? 'actif' : ''; ?>">Poule <?php echo e($p['lettre']); ?></a>
        <?php endforeach; ?>

        <?php foreach ($tableaux as $cle => $texte): ?>
            <?php if ($cle !== 'barrage'): ?>
                <a href="<?php echo e(url('impression.php?phase=' . $phaseId . '&doc=tableau&contexte=' . $cle)); ?>"
                   class="<?php echo $doc === 'tableau' && $contexte === $cle ? 'actif' : ''; ?>"><?php echo e($texte); ?></a>
            <?php endif; ?>
            <a href="<?php echo e(url('impression.php?phase=' . $phaseId . '&doc=matchs_tableau&contexte=' . $cle)); ?>"
               class="<?php echo $doc === 'matchs_tableau' && $contexte === $cle ? 'actif' : ''; ?>">Feuilles <?php echo e(mb_strtolower($texte)); ?></a>
        <?php endforeach; ?>
    </span>

    <button onclick="window.print()">Imprimer</button>
</div>

<?php if ($poules === []): ?>

    <p class="vide">Les poules de cette phase ne sont pas encore composées.</p>

<?php elseif ($doc === 'tableau'): ?>
    <?php require __DIR__ . '/templates/impression/tableau.php'; ?>
<?php elseif ($doc === 'matchs_tableau'): ?>
    <?php require __DIR__ . '/templates/impression/matchs_tableau.php'; ?>
<?php elseif ($doc === 'matchs'): ?>
    <?php require __DIR__ . '/templates/impression/matchs.php'; ?>
<?php else: ?>
    <?php require __DIR__ . '/templates/impression/poules.php'; ?>
<?php endif; ?>

</body>
</html>

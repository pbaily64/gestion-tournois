<?php

declare(strict_types=1);

/**
 * Gestion d'une phase, regroupee en onglets.
 *
 * Tout ce qui concerne une soiree tient dans cette page : la liste des
 * joueurs, la composition des poules, l'encodage poule par poule et le
 * classement general. Les onglets apparaissent au fur et a mesure de
 * l'avancement.
 *
 * Trois etats se succedent :
 *   1. aucune poule       -> liste des joueurs, bouton de generation
 *   2. poules composees   -> tableau des poules, ajustable, a valider
 *   3. rencontres creees  -> onglets d'encodage, puis classement general
 */

require __DIR__ . '/config/bootstrap.php';

exigerAccesGestion();

use RMCF\Tournois\Domain\ClassementGeneral;
use RMCF\Tournois\Domain\FormatMatch;
use RMCF\Tournois\Domain\RepartitionPoules;
use RMCF\Tournois\Repository\ClassementGeneralRepository;
use RMCF\Tournois\Repository\ClassementRepository;
use RMCF\Tournois\Repository\JoueurRepository;
use RMCF\Tournois\Repository\ParticipationRepository;
use RMCF\Tournois\Repository\PhaseRepository;
use RMCF\Tournois\Repository\PouleRepository;
use RMCF\Tournois\Repository\RencontreRepository;
use RMCF\Tournois\Repository\TableauRepository;

$pdo        = db();
$repoPhase  = new PhaseRepository($pdo);
$repoJoueur = new JoueurRepository($pdo);
$repoClsmt  = new ClassementRepository($pdo);
$repoPart   = new ParticipationRepository($pdo);
$repoPoule  = new PouleRepository($pdo);
$repoRenc   = new RencontreRepository($pdo);
$repoClgen  = new ClassementGeneralRepository($pdo);
$repoTableau = new TableauRepository($pdo);

$phaseId = (int) ($_REQUEST['phase'] ?? 0);
$phase   = $phaseId > 0 ? $repoPhase->phase($phaseId) : null;

if ($phase === null) {
    message('Phase inconnue.', 'danger');
    header('Location: ' . url('index.php'));
    exit;
}

$tournoi = $repoPhase->tournoi((int) $phase['tournoi_id']);
$format  = FormatMatch::tryFrom((string) ($phase['format_match'] ?? ''))
    ?? FormatMatch::TroisSetsSecs;

$retour = static fn (string $onglet = ''): string => url(
    'phase.php?phase=' . $phaseId . ($onglet !== '' ? '&onglet=' . $onglet : '')
);

// --- Traitement -------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    $onglet = (string) ($_POST['onglet'] ?? 'joueurs');

    try {
        switch ($_POST['action'] ?? '') {

            // --- Liste des joueurs ---------------------------------
            case 'pointage':
                $bilan = $repoPart->remplacer($phaseId, array_map('intval', (array) ($_POST['pointes'] ?? [])));
                message(sprintf('%d ajout(s), %d retrait(s).', $bilan['ajoutes'], $bilan['retires']));
                break;

            case 'nouveau_joueur':
                $pointes = array_map('intval', (array) ($_POST['pointes'] ?? []));
                $pointes[] = $repoJoueur->creer(
                    (string) ($_POST['nom'] ?? ''),
                    (string) ($_POST['prenom'] ?? ''),
                    (int) ($_POST['classement_id'] ?? 0)
                );
                $repoPart->remplacer($phaseId, $pointes);
                message('Joueur créé et ajouté à la phase.');
                break;

            case 'modifier_joueur':
                $repoJoueur->modifier(
                    (int) ($_POST['joueur'] ?? 0),
                    (string) ($_POST['nom'] ?? ''),
                    (string) ($_POST['prenom'] ?? ''),
                    (int) ($_POST['classement_id'] ?? 0)
                );
                $repoPart->rafraichirClassement($phaseId, (int) ($_POST['joueur'] ?? 0));
                message('Joueur modifié.');
                break;

            // --- Format de jeu -------------------------------------
            case 'format':
                $nouveau = FormatMatch::tryFrom((string) ($_POST['format_match'] ?? ''));

                if ($nouveau === null) {
                    throw new RuntimeException('Format inconnu.');
                }

                $bilan = $repoPhase->changerFormat($phaseId, $nouveau, $repoRenc);
                message($bilan);
                break;

            // --- Poules --------------------------------------------
            case 'composer':
                $b = $repoPoule->composer($phaseId, (int) ($_POST['nb_poules'] ?? 0));
                message(sprintf('%d poules composées. Ajustez-les si besoin, puis validez.', $b['nb_poules']));
                $onglet = 'composition';
                break;

            case 'echanger':
                $repoPoule->echanger(
                    $phaseId,
                    (int) ($_POST['a'] ?? 0),
                    (int) ($_POST['b'] ?? 0)
                );
                $onglet = 'composition';
                break;

            case 'valider_poules':
                $b = $repoPoule->validerComposition($phaseId);
                message(sprintf('Poules validées : %d matchs à disputer.', $b['nb_matchs']));
                $onglet = 'composition';
                break;

            case 'supprimer_poules':
                $repoPoule->supprimer($phaseId);
                message('Poules supprimées.');
                $onglet = 'joueurs';
                break;

            // --- Encodage ------------------------------------------
            case 'lancer':
                $repoRenc->basculerLancement((int) ($_POST['rencontre'] ?? 0));
                break;

            case 'encoder':
                $cases = [];

                foreach ((array) ($_POST['p1'] ?? []) as $i => $p1) {
                    $cases[] = [$p1, ($_POST['p2'][$i] ?? null)];
                }

                $r = $repoRenc->encoder((int) ($_POST['rencontre'] ?? 0), $cases);
                message(sprintf('Résultat enregistré : %d-%d.', $r->sets1, $r->sets2));
                break;

            case 'effacer':
                $repoRenc->effacer((int) ($_POST['rencontre'] ?? 0));
                message('Résultat effacé.');
                break;

            case 'monter_poule':
            case 'descendre_poule':
                $repoRenc->permuterPoule(
                    (int) ($_POST['poule'] ?? 0),
                    (int) ($_POST['participation'] ?? 0),
                    ($_POST['action'] === 'monter_poule') ? -1 : 1
                );
                message('Ordre ajusté.');
                break;

            // --- Classement général --------------------------------
            case 'valider_classement':
                $n = $repoClgen->valider($phaseId, $format);
                message(sprintf('Classement validé pour %d joueurs.', $n));
                $onglet = 'classement';
                break;

            case 'annuler_classement':
                $repoClgen->annuler($phaseId);
                message('Classement annulé.');
                $onglet = 'classement';
                break;

            case 'monter':
            case 'descendre':
                $repoClgen->permuter(
                    $phaseId,
                    (int) ($_POST['participation'] ?? 0),
                    ($_POST['action'] === 'monter') ? -1 : 1,
                    $format
                );
                $onglet = 'classement';
                break;

            // --- Barrages et tableaux ------------------------------
            case 'generer_tableaux':
                $b = $repoTableau->generer($phaseId, (bool) ($_POST['avec_consolation'] ?? false));
                message(sprintf(
                    '%d match(s) de barrage et %d rencontre(s) de tableau générés.',
                    $b['barrages'],
                    $b['matchs']
                ));
                $onglet = $b['barrages'] > 0 ? 'barrage' : 'final';
                break;

            case 'format_tour':
                $nouveau = FormatMatch::tryFrom((string) ($_POST['format_match'] ?? ''));

                if ($nouveau === null) {
                    throw new RuntimeException('Format inconnu.');
                }

                $tourCible = (string) ($_POST['tour'] ?? '');

                $n = $repoTableau->changerFormatTour(
                    $phaseId,
                    (string) ($_POST['contexte'] ?? 'tableau_final'),
                    $tourCible === '' ? null : $tourCible,
                    $nouveau
                );

                message(sprintf(
                    '%s pour %d rencontre(s) non encodée(s) de ce tour.',
                    $nouveau->libelle(),
                    $n
                ));
                break;

            case 'supprimer_tableaux':
                $repoTableau->supprimer($phaseId);
                message('Tableaux supprimés.');
                $onglet = 'classement';
                break;

            case 'encoder_tableau':
                $cases = [];

                foreach ((array) ($_POST['p1'] ?? []) as $i => $p1) {
                    $cases[] = [$p1, ($_POST['p2'][$i] ?? null)];
                }

                $rid = (int) ($_POST['rencontre'] ?? 0);
                $r   = $repoRenc->encoder($rid, $cases);
                $repoTableau->propager($rid);

                message(sprintf('Résultat enregistré : %d-%d.', $r->sets1, $r->sets2));
                break;

            case 'effacer_tableau':
                $repoRenc->effacer((int) ($_POST['rencontre'] ?? 0));
                message('Résultat effacé. Vérifiez les tours suivants.', 'warning');
                break;

            case 'poursuite':
                $repoClgen->enregistrerPoursuite(
                    $phaseId,
                    array_map('intval', (array) ($_POST['poursuit'] ?? []))
                );
                message('Poursuite enregistrée.');
                $onglet = 'classement';
                break;
        }
    } catch (Throwable $e) {
        message($e->getMessage(), 'danger');
    }

    header('Location: ' . $retour($onglet));
    exit;
}

// --- Etat de la phase -------------------------------------------------

$composees   = $repoPoule->existent($phaseId);
$validees    = $composees && $repoPoule->rencontresGenerees($phaseId);
$poules      = $composees ? $repoPoule->poules($phaseId) : [];
$bord        = $validees ? $repoRenc->tableauDeBord($phaseId) : ['total' => 0, 'encodes' => 0, 'en_cours' => 0, 'a_lancer' => 0];
$termine     = $validees && $bord['total'] > 0 && $bord['encodes'] === $bord['total'];
$formatFige  = !$repoPhase->formatModifiable($phaseId);
$classe      = $repoClgen->estValide($phaseId);
$tableaux    = $repoTableau->existent($phaseId);

$onglet = (string) ($_GET['onglet'] ?? '');

if ($onglet === '') {
    $onglet = $validees ? 'composition' : 'joueurs';
}

require __DIR__ . '/templates/phase/entete.php';

switch ($onglet) {
    case 'composition':
        require __DIR__ . '/templates/phase/composition.php';
        break;

    case 'classement':
        require __DIR__ . '/templates/phase/classement.php';
        break;

    case 'barrage':
    case 'final':
    case 'consolante':
        require __DIR__ . '/templates/phase/tableau.php';
        break;

    default:
        if (str_starts_with($onglet, 'poule')) {
            require __DIR__ . '/templates/phase/encodage.php';
        } else {
            require __DIR__ . '/templates/phase/joueurs.php';
        }
}

fermerPage(['https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js']);

<?php
/**
 * Onglets « Barrage », « Tableau final » et « Consolante ».
 *
 * Le tableau est presente en colonnes, un tour par colonne, comme les
 * feuilles Tab_Final et Tab_Consolation du classeur : on suit le
 * parcours d'un joueur d'un seul coup d'oeil.
 *
 * L'encodage se fait dans une fenetre, au clic sur une rencontre : cela
 * evite que la hauteur des cases varie et desaligne les colonnes.
 *
 * Le barrage, lui, n'a pas de structure d'arbre : il reste en liste.
 *
 * @var string $onglet   'barrage', 'final' ou 'consolante'
 * @var int    $phaseId
 */

declare(strict_types=1);

use RMCF\Tournois\Domain\FormatMatch;
use RMCF\Tournois\Domain\Tableau;

$contexte = match ($onglet) {
    'barrage'    => 'barrage',
    'consolante' => 'consolation',
    default      => 'tableau_final',
};

$parTour = $repoTableau->parTour($phaseId, $contexte);

// Une rencontre deja jouee fige ses joueurs ; les autres restent
// deplacables. C'est ce qui compte, non que le tableau soit vierge.
$reamenageable = false;

foreach ($parTour as $tourCourant => $rencontres) {
    if ($tourCourant !== 'barrage' && $tourCourant !== '8e') {
        continue;
    }

    foreach ($rencontres as $r) {
        if ($r['vainqueur'] === null && $r['nom_1'] !== null && $r['nom_2'] !== null) {
            $reamenageable = true;
        }
    }
}

// Joueur retenu en vue d'un echange, transmis par l'URL. La selection
// vit cote serveur : pas de dependance a JavaScript, donc pas d'echec
// silencieux possible.
$selection = null;

if (preg_match('/^(\d+)-([12])$/', (string) ($_GET['sel'] ?? ''), $m) === 1) {
    $selection = ['id' => (int) $m[1], 'cote' => (int) $m[2]];
}

$lienOnglet = static fn (string $sel = ''): string => url(
    'phase.php?phase=' . $phaseId . '&onglet=' . $onglet . ($sel !== '' ? '&sel=' . $sel : '')
);

/** Deux joueurs d'une meme poule s'affrontent-ils ? */
$fratricide = static fn (array $r): bool =>
    $r['poule_1'] !== null && $r['poule_1'] === $r['poule_2'];

/** Bloc d'un joueur : poule, nom, classement, handicap, resultat. */
$ligneJoueur = static function (array $r, int $cote) use ($selection, $lienOnglet, $onglet): string {
    $nom = $r['nom_' . $cote];

    if ($nom === null) {
        $place = $r['place_' . $cote];

        return '<div class="j vide"><span class="nom">'
            . ($place !== null
                ? 'Vainqueur barrage &mdash; place ' . (int) $place
                : 'à déterminer')
            . '</span></div>';
    }

    $h       = (int) $r['handicap'];
    $recoit  = ($cote === 2 && $h > 0) || ($cote === 1 && $h < 0);
    $gagnant = $r['vainqueur'] === (string) $cote;
    $sets    = $r['vainqueur'] !== null ? (int) $r['sets_' . $cote] : null;

    // Deplacable tant que la rencontre n'est pas jouee, et seulement au
    // premier tour ou en barrage.
    $deplacable = $r['vainqueur'] === null
        && ($r['tour'] === '8e' || $r['tour'] === null);

    $choisi = $selection !== null
        && $selection['id'] === (int) $r['id']
        && $selection['cote'] === $cote;

    $contenu = '<span class="poule">' . e((string) ($r['poule_' . $cote] ?? '')) . '</span>'
        . '<span class="nom">' . e($nom) . ' ' . e($r['prenom_' . $cote]) . '</span>'
        . '<span class="clas">' . e((string) $r['classement_' . $cote]) . '</span>'
        . '<span class="hand">' . ($recoit ? abs($h) : '') . '</span>'
        . '<span class="res">' . ($sets !== null ? $sets : '') . '</span>';

    $classe = 'j' . ($gagnant ? ' gagnant' : '') . ($choisi ? ' selectionne' : '');

    if (!$deplacable) {
        return '<div class="' . $classe . '">' . $contenu . '</div>';
    }

    // Rien de retenu : ce clic retient ce joueur.
    if ($selection === null) {
        return '<a class="' . $classe . '" href="'
            . e($lienOnglet((int) $r['id'] . '-' . $cote)) . '">' . $contenu . '</a>';
    }

    // Ce joueur est celui qui est retenu : ce clic annule.
    if ($choisi) {
        return '<a class="' . $classe . '" href="' . e($lienOnglet()) . '">' . $contenu . '</a>';
    }

    // Un autre joueur est retenu : ce clic declenche l'echange.
    return '<form method="post" class="ligne-echange">'
        . champCsrf()
        . '<input type="hidden" name="action" value="echanger_tableau">'
        . '<input type="hidden" name="onglet" value="' . e($onglet) . '">'
        . '<input type="hidden" name="r1" value="' . $selection['id'] . '">'
        . '<input type="hidden" name="c1" value="' . $selection['cote'] . '">'
        . '<input type="hidden" name="r2" value="' . (int) $r['id'] . '">'
        . '<input type="hidden" name="c2" value="' . $cote . '">'
        . '<button type="submit" class="' . $classe . '">' . $contenu . '</button>'
        . '</form>';
};

/** Sélecteur de format d'un tour, ou format figé si tout est encodé. */
$selecteurFormat = static function (array $rencontres, ?string $tourBase) use ($repoTableau, $phaseId, $contexte, $onglet): void {
    $formatTour = $repoTableau->formatDuTour($phaseId, $contexte, $tourBase);
    $reste      = false;

    foreach ($rencontres as $x) {
        if ($x['vainqueur'] === null) {
            $reste = true;
        }
    }

    if (!$reste) {
        printf(
            '<span class="text-muted small"><i class="bi bi-lock me-1"></i>%s</span>',
            e($formatTour->libelle())
        );

        return;
    }
    ?>
    <form method="post" class="d-inline">
        <?php echo champCsrf(); ?>
        <input type="hidden" name="action" value="format_tour">
        <input type="hidden" name="onglet" value="<?php echo e($onglet); ?>">
        <input type="hidden" name="contexte" value="<?php echo e($contexte); ?>">
        <input type="hidden" name="tour" value="<?php echo e((string) $tourBase); ?>">
        <select name="format_match" class="form-select form-select-sm d-inline-block" style="width:auto;"
                onchange="this.form.submit()">
            <?php foreach (FormatMatch::tous() as $f): ?>
                <option value="<?php echo e($f->value); ?>" <?php echo $f === $formatTour ? 'selected' : ''; ?>>
                    <?php echo e($f->libelle()); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php
};

/**
 * Bandeau de titre d'une rencontre, avec le bouton d'encodage.
 *
 * L'encodage passe par un bouton explicite et non par un clic sur la
 * rencontre : le clic sur un joueur sert deja a le selectionner en vue
 * d'un echange de position, et les deux gestes se confondraient.
 *
 * Ce bandeau figure sur toutes les rencontres, meme sans bouton : sa
 * hauteur constante preserve l'alignement des colonnes.
 */
$bandeau = static function (array $r, string $libelle) use ($repoTableau, $phaseId, $contexte): string {
    $encodable = $r['nom_1'] !== null && $r['nom_2'] !== null && $r['vainqueur'] === null;

    $html = '<div class="match-titre"><span>' . e($libelle) . '</span>';

    if ($encodable) {
        $format = $repoTableau->formatDuTour($phaseId, $contexte, $r['tour'] ?? null);

        $html .= '<button type="button" class="btn-encoder" @click="ouvrir('
            . (int) $r['id'] . ', '
            . e(json_encode($r['nom_1'] . ' ' . $r['prenom_1'], JSON_UNESCAPED_UNICODE)) . ', '
            . e(json_encode($r['nom_2'] . ' ' . $r['prenom_2'], JSON_UNESCAPED_UNICODE)) . ', '
            . $format->nombreDeCases() . ')">Encoder</button>';
    } elseif ($r['vainqueur'] !== null) {
        $html .= '<span class="score">' . (int) $r['sets_1'] . '&ndash;' . (int) $r['sets_2'] . '</span>';
    }

    return $html . '</div>';
};
?>

<?php if ($parTour === []): ?>

    <div class="alert alert-info">
        <?php echo $onglet === 'barrage'
            ? 'Aucun barrage n\'est nécessaire pour cette phase.'
            : 'Ce tableau n\'a pas été généré.'; ?>
    </div>

<?php else: ?>

<div x-data="tableauInteractif()">

<?php if ($reamenageable): ?>
    <?php
    $conflits = 0;

    foreach ($parTour as $rencontres) {
        foreach ($rencontres as $r) {
            if ($fratricide($r)) {
                $conflits++;
            }
        }
    }
    ?>
    <div class="alert <?php echo $conflits > 0 ? 'alert-warning' : 'alert-light'; ?> py-2">
        <?php if ($conflits > 0): ?>
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong><?php echo $conflits; ?></strong>
            rencontre<?php echo $conflits > 1 ? 's opposent' : ' oppose'; ?>
            deux joueurs d'une même poule — encadrée<?php echo $conflits > 1 ? 's' : ''; ?> en rouge.
            <br>
        <?php else: ?>
            <i class="bi bi-info-circle me-1"></i>
        <?php endif; ?>
        Cliquez sur deux joueurs pour les échanger de position. Une rencontre
        déjà jouée fige ses joueurs ; les autres restent déplaçables.
        <?php if ($selection !== null): ?>
            <span class="d-block mt-1 fw-semibold">
                <i class="bi bi-arrow-left-right me-1"></i>
                Un joueur est retenu : cliquez celui avec qui l'échanger, ou
                <a href="<?php echo e($lienOnglet()); ?>">annulez</a>.
            </span>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php if ($contexte === 'barrage'): ?>

    <!-- Le barrage n'est pas un arbre : liste simple -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h2 class="h6 mb-0" style="color: var(--primary-color);">Matchs de barrage</h2>
        <?php $selecteurFormat($parTour['barrage'] ?? [], null); ?>
    </div>

    <div class="arbre">
        <div class="tour" style="justify-content:flex-start;">
            <?php foreach ($parTour['barrage'] ?? [] as $r): ?>
                <div class="match<?php echo $r['vainqueur'] !== null ? ' joue' : ''; ?><?php echo $fratricide($r) ? ' fratricide' : ''; ?>">
                    <?php echo $bandeau($r, 'Barrage ' . (int) $r['ordre'] . ' — place ' . (int) $r['place_attribuee']); ?>
                    <?php echo $ligneJoueur($r, 1); ?>
                    <?php echo $ligneJoueur($r, 2); ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

<?php else: ?>

    <!-- Barre des formats, un par tour -->
    <div class="d-flex flex-wrap gap-3 align-items-center mb-3">
        <?php foreach (Tableau::TOURS as $t): ?>
            <?php if (!isset($parTour[$t])) { continue; } ?>
            <span class="small">
                <span class="text-muted"><?php echo e(Tableau::libelle($t)); ?> :</span>
                <?php $selecteurFormat($parTour[$t], $t); ?>
            </span>
        <?php endforeach; ?>
    </div>

    <div class="arbre">
        <?php foreach (Tableau::TOURS as $t): ?>
            <?php if (!isset($parTour[$t])) { continue; } ?>
            <div class="tour">
                <div class="tour-titre"><?php echo e(Tableau::libelle($t)); ?></div>
                <?php foreach ($parTour[$t] as $r): ?>
                    <div class="match<?php echo $r['vainqueur'] !== null ? ' joue' : ''; ?><?php echo $fratricide($r) ? ' fratricide' : ''; ?>">
                        <?php echo $bandeau($r, (string) (int) $r['ordre']); ?>
                        <?php echo $ligneJoueur($r, 1); ?>
                        <?php echo $ligneJoueur($r, 2); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <!-- Classement final -->
        <?php
        $finale    = $parTour['finale'][0] ?? null;
        $vainqueur = null;
        $finaliste = null;

        if ($finale !== null && $finale['vainqueur'] !== null) {
            $g = (int) $finale['vainqueur'];
            $p = $g === 1 ? 2 : 1;
            $vainqueur = $finale['nom_' . $g] . ' ' . $finale['prenom_' . $g];
            $finaliste = $finale['nom_' . $p] . ' ' . $finale['prenom_' . $p];
        }
        ?>
        <div class="tour classement-final">
            <div class="tour-titre">Classement</div>
            <div class="podium">
                <div class="rang">
                    <span class="place">1<sup>er</sup></span>
                    <span class="gagne"><?php echo $vainqueur !== null ? e($vainqueur) : '—'; ?></span>
                </div>
                <div class="rang">
                    <span class="place">2<sup>e</sup></span>
                    <span class="perd"><?php echo $finaliste !== null ? e($finaliste) : '—'; ?></span>
                </div>

                <?php if (isset($parTour['demie'])): ?>
                    <div class="perdants">
                        <div class="text-muted small mb-1">Perdants des demi-finales</div>
                        <?php foreach ($parTour['demie'] as $d): ?>
                            <?php if ($d['vainqueur'] === null) { continue; } ?>
                            <?php $p = $d['vainqueur'] === '1' ? 2 : 1; ?>
                            <div><?php echo e($d['nom_' . $p] . ' ' . $d['prenom_' . $p]); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php endif; ?>

    <!-- Fenêtre d'encodage -->
    <div class="modal fade" id="modalScore" x-ref="modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <?php echo champCsrf(); ?>
                    <input type="hidden" name="action" value="encoder_tableau">
                    <input type="hidden" name="onglet" value="<?php echo e($onglet); ?>">
                    <input type="hidden" name="rencontre" :value="rencontre">

                    <div class="modal-header">
                        <h5 class="modal-title">
                            <span x-text="j1"></span>
                            <span class="text-muted mx-1">contre</span>
                            <span x-text="j2"></span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">
                            Points de chaque set. Les sets non joués restent vides.
                        </p>
                        <template x-for="i in cases" :key="i">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="text-muted small" style="width:3.5rem;">Set <span x-text="i"></span></span>
                                <input type="text" name="p1[]" maxlength="2" inputmode="numeric"
                                       pattern="[0-9]*" autocomplete="off"
                                       class="form-control form-control-sm" style="width:3.5rem;text-align:center;">
                                <span class="text-muted">-</span>
                                <input type="text" name="p2[]" maxlength="2" inputmode="numeric"
                                       pattern="[0-9]*" autocomplete="off"
                                       class="form-control form-control-sm" style="width:3.5rem;text-align:center;">
                            </div>
                        </template>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Corriger une rencontre déjà encodée -->
    <div class="mt-4">
        <details>
            <summary class="text-muted small">Corriger un résultat</summary>
            <div class="mt-2">
                <?php foreach ($parTour as $tour => $rencontres): ?>
                    <?php foreach ($rencontres as $r): ?>
                        <?php if ($r['vainqueur'] === null || $r['nom_1'] === null || $r['nom_2'] === null) { continue; } ?>
                        <form method="post" class="d-inline-block me-2 mb-2">
                            <?php echo champCsrf(); ?>
                            <input type="hidden" name="action" value="effacer_tableau">
                            <input type="hidden" name="rencontre" value="<?php echo (int) $r['id']; ?>">
                            <input type="hidden" name="onglet" value="<?php echo e($onglet); ?>">
                            <button class="btn btn-sm btn-outline-danger">
                                <?php echo e($r['nom_1']); ?> &ndash; <?php echo e($r['nom_2']); ?>
                                (<?php echo (int) $r['sets_1']; ?>-<?php echo (int) $r['sets_2']; ?>)
                            </button>
                        </form>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </details>
    </div>
</div>

<script>
function tableauInteractif() {
    return {
        rencontre: 0, j1: '', j2: '', cases: 3,

        ouvrir(id, n1, n2, cases) {
            this.rencontre = id;
            this.j1 = n1;
            this.j2 = n2;
            this.cases = cases;
            new bootstrap.Modal(document.getElementById('modalScore')).show();
        }
    };
}
</script>

<?php endif; ?>

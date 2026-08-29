<?php
/**
 * Tableau a elimination directe sur papier, en arbre, comme les
 * feuilles Tab_Final et Tab_Consolation du classeur.
 *
 * S'imprime en A4 couche : quatre tours et la colonne de classement
 * ne tiennent pas en portrait.
 *
 * @var string $contexte
 * @var string $entete
 */

declare(strict_types=1);

use RMCF\Tournois\Domain\Tableau;

$parTour = $repoTableau->parTour($phaseId, $contexte);

$ligne = static function (array $r, int $cote): string {
    $nom = $r['nom_' . $cote];

    if ($nom === null) {
        return '<div class="tj vide">&nbsp;</div>';
    }

    $h      = (int) $r['handicap'];
    $recoit = ($cote === 2 && $h > 0) || ($cote === 1 && $h < 0);
    $sets   = $r['vainqueur'] !== null ? (int) $r['sets_' . $cote] : null;

    return '<div class="tj' . ($r['vainqueur'] === (string) $cote ? ' gagnant' : '') . '">'
        . '<span class="p">' . e((string) ($r['poule_' . $cote] ?? '')) . '</span>'
        . '<span class="n">' . e($nom) . ' ' . e($r['prenom_' . $cote]) . '</span>'
        . '<span class="c">' . e((string) $r['classement_' . $cote]) . '</span>'
        . '<span class="h">' . ($recoit ? abs($h) : '') . '</span>'
        . '<span class="r">' . ($sets !== null ? $sets : '') . '</span>'
        . '</div>';
};
?>

<h1 class="titre-doc"><?php echo e($titre); ?></h1>
<p class="sous-titre"><?php echo e($entete); ?></p>

<div class="arbre-papier">
    <?php foreach (Tableau::TOURS as $t): ?>
        <?php if (!isset($parTour[$t])) { continue; } ?>
        <div class="tp">
            <div class="tp-titre"><?php echo e(Tableau::libelle($t)); ?></div>
            <?php foreach ($parTour[$t] as $r): ?>
                <div class="tm">
                    <?php echo $ligne($r, 1); ?>
                    <?php echo $ligne($r, 2); ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

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
    <div class="tp tp-classement">
        <div class="tp-titre">Classement</div>
        <div class="cl"><span class="rg">1<sup>er</sup></span>
            <span class="v"><?php echo $vainqueur !== null ? e($vainqueur) : ''; ?></span></div>
        <div class="cl"><span class="rg">2<sup>e</sup></span>
            <span class="f"><?php echo $finaliste !== null ? e($finaliste) : ''; ?></span></div>

        <?php if (isset($parTour['demie'])): ?>
            <div class="cl-perdants">
                <div class="petit">Perdants des demi-finales</div>
                <?php foreach ($parTour['demie'] as $d): ?>
                    <?php if ($d['vainqueur'] === null) { continue; } ?>
                    <?php $p = $d['vainqueur'] === '1' ? 2 : 1; ?>
                    <div><?php echo e($d['nom_' . $p] . ' ' . $d['prenom_' . $p]); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

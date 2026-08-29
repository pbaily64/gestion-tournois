<?php
/**
 * Feuilles de match d'un barrage ou d'un tableau, sur le gabarit
 * « Template » du classeur, adapte jusqu'a cinq sets.
 *
 * Seules les rencontres dont les deux joueurs sont connus donnent lieu
 * a une feuille : les tours suivants s'impriment au fur et a mesure.
 *
 * @var string $contexte
 * @var string $entete
 */

declare(strict_types=1);

use RMCF\Tournois\Domain\Tableau;

$parTour = $repoTableau->parTour($phaseId, $contexte);
$nom     = $tableaux[$contexte] ?? '';
$aucune  = true;
?>

<?php foreach ($parTour as $tour => $rencontres): ?>
    <?php
    $tourBase = $tour === 'barrage' ? null : $tour;
    $cases    = $repoTableau->formatDuTour($phaseId, $contexte, $tourBase)->nombreDeCases();
    ?>
    <?php foreach ($rencontres as $r): ?>
        <?php
        // Un match sans ses deux joueurs n'a pas de feuille a imprimer.
        if ($r['nom_1'] === null || $r['nom_2'] === null) {
            continue;
        }

        $aucune = false;
        $h      = (int) $r['handicap'];
        $hand1  = $h < 0 ? abs($h) : 0;
        $hand2  = $h > 0 ? $h : 0;
        ?>
        <div class="feuille">
            <div class="feuille-entete">
                <span class="feuille-poule">
                    <?php echo e($tour === 'barrage' ? 'Barrage' : Tableau::libelle($tour)); ?>
                </span>
                <span class="feuille-numero">
                    <?php echo e($nom); ?> &mdash; match <?php echo (int) $r['ordre']; ?>
                </span>
                <span class="feuille-contexte"><?php echo e($entete); ?></span>
            </div>

            <table class="bordereau">
                <thead>
                    <tr>
                        <th class="col-nom"></th>
                        <th class="col-etroite">POULE</th>
                        <th class="col-etroite">CLAS.</th>
                        <th class="col-etroite">HAND</th>
                        <?php for ($i = 1; $i <= $cases; $i++): ?>
                            <th class="col-set">SET <?php echo $i; ?></th>
                        <?php endfor; ?>
                        <th class="col-etroite">RES.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ([1, 2] as $cote): ?>
                        <tr>
                            <td class="col-nom">
                                <?php echo e($r['nom_' . $cote]); ?> <?php echo e($r['prenom_' . $cote]); ?>
                            </td>
                            <td class="col-etroite"><?php echo e((string) ($r['poule_' . $cote] ?? '')); ?></td>
                            <td class="col-etroite"><?php echo e((string) $r['classement_' . $cote]); ?></td>
                            <td class="col-etroite hand">
                                <?php
                                $v = $cote === 1 ? $hand1 : $hand2;
                                echo $v > 0 ? $v : '';
                                ?>
                            </td>
                            <?php for ($i = 1; $i <= $cases; $i++): ?>
                                <td class="col-set"></td>
                            <?php endfor; ?>
                            <td class="col-etroite"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<?php if ($aucune): ?>
    <p class="vide">
        Aucune rencontre à imprimer : les joueurs des tours suivants ne sont pas
        encore connus.
    </p>
<?php endif; ?>

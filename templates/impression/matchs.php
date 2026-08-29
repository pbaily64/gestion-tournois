<?php
/**
 * Feuilles de match, reprises du gabarit « Template » du classeur :
 * une ligne par joueur, avec classement, handicap, les cases de set et
 * le resultat. Le nombre de cases suit le format de la phase, jusqu'a
 * cinq sets.
 *
 * @var list<array> $poules
 * @var string      $filtre lettre de poule, ou chaine vide pour toutes
 * @var FormatMatch $format
 * @var string      $entete
 */

declare(strict_types=1);

$cases = $format->nombreDeCases();
?>

<?php foreach ($poules as $p): ?>
    <?php if ($filtre !== '' && $filtre !== (string) $p['lettre']) { continue; } ?>

    <?php foreach ($repoPoule->rencontres((int) $p['id']) as $r): ?>
        <?php
        $h = (int) $r['handicap'];

        // Le handicap est porte par celui qui le recoit : positif
        // signifie que le joueur 1 est le plus fort, donc que le
        // joueur 2 part avec l'avance.
        $hand1 = $h < 0 ? abs($h) : 0;
        $hand2 = $h > 0 ? $h : 0;
        ?>
        <div class="feuille">
            <div class="feuille-entete">
                <span class="feuille-poule">Poule <?php echo e($p['lettre']); ?></span>
                <span class="feuille-numero">Match <?php echo (int) $r['ordre']; ?></span>
                <span class="feuille-contexte"><?php echo e($entete); ?></span>
                <span class="feuille-arbitre">
                    <?php if ($r['nom_arbitre'] !== null): ?>
                        Arbitre : <?php echo e($r['nom_arbitre']); ?> <?php echo e($r['prenom_arbitre']); ?>
                    <?php endif; ?>
                </span>
            </div>

            <table class="bordereau">
                <thead>
                    <tr>
                        <th class="col-nom"></th>
                        <th class="col-etroite">CLAS.</th>
                        <th class="col-etroite">HAND</th>
                        <?php for ($i = 1; $i <= $cases; $i++): ?>
                            <th class="col-set">SET <?php echo $i; ?></th>
                        <?php endfor; ?>
                        <th class="col-etroite">RES.</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="col-nom">
                            <?php echo e($r['nom_1']); ?> <?php echo e($r['prenom_1']); ?>
                        </td>
                        <td class="col-etroite"><?php echo e($r['classement_1']); ?></td>
                        <td class="col-etroite hand"><?php echo $hand1 > 0 ? $hand1 : ''; ?></td>
                        <?php for ($i = 1; $i <= $cases; $i++): ?>
                            <td class="col-set"></td>
                        <?php endfor; ?>
                        <td class="col-etroite"></td>
                    </tr>
                    <tr>
                        <td class="col-nom">
                            <?php echo e($r['nom_2']); ?> <?php echo e($r['prenom_2']); ?>
                        </td>
                        <td class="col-etroite"><?php echo e($r['classement_2']); ?></td>
                        <td class="col-etroite hand"><?php echo $hand2 > 0 ? $hand2 : ''; ?></td>
                        <?php for ($i = 1; $i <= $cases; $i++): ?>
                            <td class="col-set"></td>
                        <?php endfor; ?>
                        <td class="col-etroite"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>

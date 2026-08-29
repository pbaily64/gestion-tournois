<?php
/**
 * Tableau des poules, dispose par trois en largeur comme la feuille
 * « General » du classeur. Sert a chercher un joueur d'un coup d'oeil
 * sans passer d'une poule a l'autre.
 *
 * @var list<array> $poules
 * @var string      $entete
 */

declare(strict_types=1);
?>

<h1 class="titre-doc">Tableau des poules</h1>
<p class="sous-titre"><?php echo e($entete); ?></p>

<div class="grille-poules">
    <?php foreach ($poules as $p): ?>
        <table class="poule">
            <thead>
                <tr>
                    <th colspan="2">Poule <?php echo e($p['lettre']); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($p['membres'] as $m): ?>
                    <tr>
                        <td class="nom">
                            <span class="lettre"><?php echo e($m['lettre']); ?></span>
                            <?php echo e($m['nom']); ?> <?php echo e($m['prenom']); ?>
                        </td>
                        <td class="clas"><?php echo e($m['classement']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endforeach; ?>
</div>

<?php
/**
 * Onglet « Classement général ».
 *
 * Ordonne tous les joueurs de la phase, toutes poules confondues.
 * Determine les qualifications et la composition des barrages.
 *
 * @var int $phaseId
 */

declare(strict_types=1);

use RMCF\Tournois\Domain\ClassementGeneral;

$valide     = $repoClgen->estValide($phaseId);
$classement = $repoClgen->ordonner($phaseId, $format);

$egalites = !$valide && ClassementGeneral::comporteDesEgalites($classement);
?>

<?php if ($egalites): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <?php if (!$format->setsComparables()): ?>
            En <?php echo e(mb_strtolower($format->libelle())); ?>, les sets ne sont pas
            comparables entre joueurs de poules différentes : le classement s'arrête
            après le nombre de victoires. Les égalités seront à trancher à la main
            après validation.
        <?php else: ?>
            Des joueurs restent à égalité sur tous les critères.
            Vous pourrez les réordonner après validation.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="table-responsive mb-3">
    <table class="table table-hover align-middle">
        <thead>
            <tr>
                <th style="width:3rem;">Pl.</th>
                <th>Joueur</th>
                <th class="text-center">Poule</th>
                <th class="text-center">Place</th>
                <th class="text-center">V</th>
                <th class="text-center">Sets +</th>
                <th class="text-center">Diff</th>
                <th class="text-center">Poursuit</th>
                <?php if (!$valide): ?>
                    <th style="width:5rem;"></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($classement as $i => $l): ?>
                <tr<?php echo !empty($l['ex_aequo']) ? ' class="table-warning"' : ''; ?>>
                    <td class="fw-semibold"><?php echo (int) $l['place']; ?></td>
                    <td>
                        <strong><?php echo e($l['nom'] ?? ''); ?></strong>
                        <?php echo e($l['prenom'] ?? ''); ?>
                        <span class="text-muted small"><?php echo e($l['classement'] ?? ''); ?></span>
                        <?php if (!empty($l['ex_aequo'])): ?>
                            <span class="badge bg-warning text-dark ms-1">ex æquo</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if (!empty($l['poule'])): ?>
                            <span class="lettre-poule"><?php echo e($l['poule']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?php echo $l['place_poule'] !== null ? (int) $l['place_poule'] : '—'; ?></td>
                    <td class="text-center"><?php echo (int) $l['victoires']; ?></td>
                    <td class="text-center"><?php echo (int) $l['sets_pour']; ?></td>
                    <td class="text-center">
                        <?php printf('%s%d', (int) $l['diff'] > 0 ? '+' : '', (int) $l['diff']); ?>
                    </td>
                    <td class="text-center">
                        <input class="form-check-input" type="checkbox" form="fPoursuite"
                               name="poursuit[]" value="<?php echo (int) $l['id']; ?>"
                               <?php echo ($l['poursuit'] === null || (int) $l['poursuit'] === 1) ? 'checked' : ''; ?>>
                    </td>
                    <?php if (!$valide): ?>
                        <td class="text-nowrap text-end">
                            <form method="post" class="d-inline">
                                <?php echo champCsrf(); ?>
                                <input type="hidden" name="action" value="monter">
                                <input type="hidden" name="participation" value="<?php echo (int) $l['id']; ?>">
                                <button class="btn btn-sm btn-link p-0 text-muted"
                                        <?php echo $i === 0 ? 'disabled' : ''; ?>>
                                    <i class="bi bi-arrow-up"></i>
                                </button>
                            </form>
                            <form method="post" class="d-inline">
                                <?php echo champCsrf(); ?>
                                <input type="hidden" name="action" value="descendre">
                                <input type="hidden" name="participation" value="<?php echo (int) $l['id']; ?>">
                                <button class="btn btn-sm btn-link p-0 text-muted"
                                        <?php echo $i === count($classement) - 1 ? 'disabled' : ''; ?>>
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Poursuite : independante de la validation. Un joueur annonce qu'il
     s'arrete pendant qu'on regarde le classement, pas apres. -->
<form method="post" id="fPoursuite" class="mb-3">
    <?php echo champCsrf(); ?>
    <input type="hidden" name="action" value="poursuite">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-save me-1"></i>Enregistrer qui poursuit
    </button>
    <span class="text-muted small ms-2">
        Décochez les joueurs qui s'arrêtent. Ils conservent leurs points de poule
        mais ne figureront ni dans les barrages ni dans les tableaux.
    </span>
</form>

<?php if ($valide && !$tableaux): ?>
    <?php
    // Previsionnel : ce que donnerait la generation, selon que
    // l'organisateur ouvre ou non une consolante.
    $avecConso = (bool) ($phase['avec_consolation'] ?? 1);
    $prevu     = $repoTableau->previsionnel($phaseId, $avecConso);
    $prevuSans = $repoTableau->previsionnel($phaseId, false);
    ?>

    <div class="card mb-3">
        <div class="card-header fw-semibold">
            <i class="bi bi-diagram-2 me-1"></i>Générer les tableaux
        </div>
        <div class="card-body">
            <form method="post">
                <?php echo champCsrf(); ?>
                <input type="hidden" name="action" value="generer_tableaux">

                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="avec_consolation"
                           value="1" id="avecConso" <?php echo $avecConso ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="avecConso">
                        <strong>Avec consolante</strong>
                        <span class="text-muted small d-block"><?php echo e($prevu->resume()); ?></span>
                    </label>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="radio" name="avec_consolation"
                           value="0" id="sansConso" <?php echo $avecConso ? '' : 'checked'; ?>>
                    <label class="form-check-label" for="sansConso">
                        <strong>Sans consolante</strong>
                        <span class="text-muted small d-block"><?php echo e($prevuSans->resume()); ?></span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="bi bi-diagram-2 me-1"></i>Générer les tableaux
                </button>
                <span class="text-muted small ms-2">
                    Seuls les joueurs cochés « Poursuit » sont pris en compte.
                </span>
            </form>
        </div>
    </div>

    <form method="post"
          onsubmit="return confirm('Annuler la validation ? Le classement redeviendra modifiable.');">
        <?php echo champCsrf(); ?>
        <input type="hidden" name="action" value="annuler_classement">
        <button type="submit" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Annuler la validation
        </button>
    </form>

<?php elseif ($valide): ?>

    <form method="post"
          onsubmit="return confirm('Supprimer les barrages et les tableaux, avec les résultats déjà encodés ?');">
        <?php echo champCsrf(); ?>
        <input type="hidden" name="action" value="supprimer_tableaux">
        <button type="submit" class="btn btn-outline-danger">
            <i class="bi bi-trash me-1"></i>Supprimer les tableaux
        </button>
        <span class="text-muted small ms-2">
            Les tableaux sont générés : le classement ne peut plus être modifié.
        </span>
    </form>

<?php else: ?>
    <form method="post">
        <?php echo champCsrf(); ?>
        <input type="hidden" name="action" value="valider_classement">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-check2-circle me-1"></i>Valider le classement général
        </button>
    </form>
    <p class="text-muted small mt-2">
        Utilisez les flèches pour remonter ou descendre un joueur d'une place,
        notamment pour trancher une égalité. La validation fige alors le
        classement et donne accès à la génération des tableaux.
    </p>
<?php endif; ?>

<div class="card border-0 mt-4" style="background: var(--gray-light);">
    <div class="card-body small text-muted">
        <strong>Critères :</strong> place obtenue en poule, puis nombre de victoires<?php
        echo $format->setsComparables() ? ', puis sets gagnés, puis différence de sets' : '';
        ?>.
    </div>
</div>

<?php
/**
 * En-tete commun a tous les onglets d'une phase :
 * titre, format de jeu, avancement, et barre d'onglets.
 *
 * @var array  $phase
 * @var array  $tournoi
 * @var string $onglet
 */

declare(strict_types=1);

use RMCF\Tournois\Domain\FormatMatch;

ouvrirPage(($phase['libelle'] ?? 'Phase') . ' — ' . ($tournoi['libelle'] ?? ''));
?>

<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <h1 class="h4 mb-1" style="color: var(--primary-color);">
            <?php echo e($phase['libelle'] ?? 'Phase'); ?>
        </h1>
        <p class="text-muted mb-0 small">
            <?php echo e($tournoi['libelle'] ?? ''); ?>
            <?php if ($phase['date_phase']): ?>
                &mdash; <?php echo formatDate($phase['date_phase']); ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="text-nowrap">
        <?php if ($composees): ?>
            <a href="<?php echo e(url('impression.php?phase=' . $phaseId)); ?>"
               target="_blank" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Imprimer
            </a>
        <?php endif; ?>
        <a href="<?php echo e(url('index.php?tournoi=' . (int) $phase['tournoi_id'])); ?>"
           class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Tournois
        </a>
    </div>
</div>

<!-- Format de jeu, visible en permanence -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-center">
            <div class="col-md-5">
                <form method="post" class="d-flex align-items-center gap-2">
                    <?php echo champCsrf(); ?>
                    <input type="hidden" name="action" value="format">
                    <input type="hidden" name="onglet" value="<?php echo e($onglet); ?>">
                    <label for="format_match" class="form-label mb-0 small text-muted text-nowrap">
                        Format des poules
                    </label>
                    <select name="format_match" id="format_match" class="form-select form-select-sm"
                            onchange="if (this.dataset.avertir) { if (confirm(this.dataset.avertir)) this.form.submit(); else this.value = this.dataset.actuel; } else this.form.submit();"
                            data-actuel="<?php echo e($format->value); ?>"
                            <?php if ($validees): ?>
                                data-avertir="Changer le format entraîne le recalcul des classements de poule. Les résultats devenus impossibles seront signalés. Continuer ?"
                            <?php endif; ?>>
                        <?php foreach (FormatMatch::tous() as $f): ?>
                            <option value="<?php echo e($f->value); ?>"
                                <?php echo $f === $format ? 'selected' : ''; ?>>
                                <?php echo e($f->libelle()); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-muted small text-nowrap"><?php echo $format->nombreDeCases(); ?> cases</span>
                </form>
            </div>

            <?php if ($validees): ?>
                <div class="col-md-7">
                    <div class="d-flex gap-3 justify-content-md-end small">
                        <span><strong><?php echo $bord['encodes']; ?></strong> / <?php echo $bord['total']; ?> encodés</span>
                        <span class="text-warning"><strong><?php echo $bord['en_cours']; ?></strong> en cours</span>
                        <span class="text-muted"><strong><?php echo $bord['a_lancer']; ?></strong> à lancer</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Onglets -->
<ul class="nav nav-tabs mb-3 flex-wrap">
    <li class="nav-item">
        <a class="nav-link <?php echo $onglet === 'joueurs' ? 'active' : ''; ?>"
           href="<?php echo e($retour('joueurs')); ?>">
            <i class="bi bi-people me-1"></i>Liste des joueurs
        </a>
    </li>

    <?php if ($composees): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $onglet === 'composition' ? 'active' : ''; ?>"
               href="<?php echo e($retour('composition')); ?>">
                <i class="bi bi-grid-3x3 me-1"></i>Tableau des poules
            </a>
        </li>
    <?php endif; ?>

    <?php if ($validees): ?>
        <?php foreach ($poules as $p): ?>
            <?php
            $restants = 0;

            foreach ($repoPoule->rencontres((int) $p['id']) as $r) {
                if ($r['vainqueur'] === null) {
                    $restants++;
                }
            }

            $cle = 'poule' . $p['lettre'];
            ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $onglet === $cle ? 'active' : ''; ?>"
                   href="<?php echo e($retour($cle)); ?>">
                    Poule <?php echo e($p['lettre']); ?>
                    <?php if ($restants > 0): ?>
                        <span class="badge bg-secondary ms-1"><?php echo $restants; ?></span>
                    <?php else: ?>
                        <i class="bi bi-check2 text-success ms-1"></i>
                    <?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($termine): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $onglet === 'classement' ? 'active' : ''; ?>"
               href="<?php echo e($retour('classement')); ?>">
                <i class="bi bi-trophy me-1"></i>Classement général
            </a>
        </li>
    <?php elseif ($validees): ?>
        <li class="nav-item">
            <span class="nav-link disabled text-muted"
                  title="Disponible quand tous les matchs sont encodés">
                <i class="bi bi-trophy me-1"></i>Classement général
            </span>
        </li>
    <?php endif; ?>

    <?php if ($tableaux): ?>
        <?php
        $ongletsTableau = ['final' => 'Tableau final'];

        if ($repoTableau->parTour($phaseId, 'barrage') !== []) {
            $ongletsTableau = ['barrage' => 'Barrage'] + $ongletsTableau;
        }

        if ($repoTableau->parTour($phaseId, 'consolation') !== []) {
            $ongletsTableau['consolante'] = 'Consolante';
        }
        ?>
        <?php foreach ($ongletsTableau as $cle => $texte): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $onglet === $cle ? 'active' : ''; ?>"
                   href="<?php echo e($retour($cle)); ?>">
                    <i class="bi bi-diagram-2 me-1"></i><?php echo e($texte); ?>
                </a>
            </li>
        <?php endforeach; ?>
    <?php endif; ?>
</ul>

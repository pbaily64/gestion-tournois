<?php
/**
 * Onglet d'une poule : encodage des scores.
 *
 * L'ordre des matchs est conseille, pas impose : un joueur arrive en
 * retard, une poule plus fournie doit avancer plus vite. Chaque
 * rencontre est independamment lancable et encodable.
 *
 * @var string $onglet  'pouleA', 'pouleB', ...
 * @var list<array> $poules
 */

declare(strict_types=1);

$lettre = substr($onglet, 5);
$poule  = null;

foreach ($poules as $p) {
    if ((string) $p['lettre'] === $lettre) {
        $poule = $p;
    }
}

if ($poule === null) {
    echo '<div class="alert alert-warning">Poule introuvable.</div>';
    return;
}

$pouleId    = (int) $poule['id'];
$rencontres = $repoPoule->rencontres($pouleId);
$cases      = $format->nombreDeCases();

/** Manches encodees d'une rencontre. */
$manchesDe = static function (int $rencontreId) use ($pdo): array {
    $st = $pdo->prepare(
        'SELECT numero, points_1, points_2 FROM ' . table('manche')
        . ' WHERE rencontre_id = ? ORDER BY numero'
    );
    $st->execute([$rencontreId]);

    $out = [];

    foreach ($st->fetchAll() as $m) {
        $out[(int) $m['numero']] = [(int) $m['points_1'], (int) $m['points_2']];
    }

    return $out;
};
?>

<div class="row g-4">
    <div class="col-xl-8">
        <?php foreach ($rencontres as $r): ?>
            <?php
            $encodee = $r['vainqueur'] !== null;
            $lancee  = $r['lancee_le'] !== null && !$encodee;
            $manches = $encodee ? $manchesDe((int) $r['id']) : [];
            $h       = (int) $r['handicap'];
            ?>
            <div class="card mb-2 rencontre <?php echo $encodee ? 'encodee' : ($lancee ? 'en-cours' : ''); ?>"
                 id="r<?php echo (int) $r['id']; ?>">
                <div class="card-body py-2">

                    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap">
                        <div>
                            <span class="text-muted me-2"><?php echo (int) $r['ordre']; ?>.</span>
                            <span class="lettre-poule"><?php echo e($r['lettre_1']); ?></span>
                            <strong><?php echo e($r['nom_1']); ?></strong> <?php echo e($r['prenom_1']); ?>
                            <span class="text-muted mx-1">contre</span>
                            <span class="lettre-poule"><?php echo e($r['lettre_2']); ?></span>
                            <strong><?php echo e($r['nom_2']); ?></strong> <?php echo e($r['prenom_2']); ?>
                        </div>
                        <div class="text-nowrap small">
                            <?php if ($h !== 0): ?>
                                <span class="badge bg-secondary">
                                    <?php printf('%d à %s', abs($h), e($h > 0 ? $r['lettre_2'] : $r['lettre_1'])); ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($r['nom_arbitre'] !== null): ?>
                                <span class="text-muted ms-1">
                                    arb. <span class="lettre-poule"><?php echo e($r['lettre_arbitre']); ?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($encodee): ?>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-success fs-6 me-2">
                                    <?php echo (int) $r['sets_1']; ?> &ndash; <?php echo (int) $r['sets_2']; ?>
                                </span>
                                <span class="text-muted small">
                                    <?php
                                    $bouts = [];

                                    foreach ($manches as $m) {
                                        $bouts[] = e($m[0] . '-' . $m[1]);
                                    }

                                    echo implode(' &nbsp; ', $bouts);
                                    ?>
                                </span>
                            </div>
                            <form method="post" class="d-inline">
                                <?php echo champCsrf(); ?>
                                <input type="hidden" name="action" value="effacer">
                                <input type="hidden" name="rencontre" value="<?php echo (int) $r['id']; ?>">
                                <input type="hidden" name="onglet" value="<?php echo e($onglet); ?>">
                                <button class="btn btn-sm btn-link text-danger p-0">Corriger</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form method="post" class="d-flex align-items-center gap-2 flex-wrap">
                            <?php echo champCsrf(); ?>
                            <input type="hidden" name="action" value="encoder">
                            <input type="hidden" name="rencontre" value="<?php echo (int) $r['id']; ?>">
                            <input type="hidden" name="onglet" value="<?php echo e($onglet); ?>">

                            <?php for ($i = 0; $i < $cases; $i++): ?>
                                <span class="case-set" style="display:inline-flex;align-items:center;gap:.2rem;">
                                    <input type="text" name="p1[]" maxlength="2" inputmode="numeric"
                                           pattern="[0-9]*" autocomplete="off"
                                           class="form-control form-control-sm"
                                           style="width:2.9rem;text-align:center;padding:.25rem;">
                                    <span class="text-muted">-</span>
                                    <input type="text" name="p2[]" maxlength="2" inputmode="numeric"
                                           pattern="[0-9]*" autocomplete="off"
                                           class="form-control form-control-sm"
                                           style="width:2.9rem;text-align:center;padding:.25rem;">
                                </span>
                            <?php endfor; ?>

                            <button class="btn btn-sm btn-primary ms-auto"><i class="bi bi-check-lg"></i></button>
                        </form>

                        <form method="post" class="mt-1">
                            <?php echo champCsrf(); ?>
                            <input type="hidden" name="action" value="lancer">
                            <input type="hidden" name="rencontre" value="<?php echo (int) $r['id']; ?>">
                            <input type="hidden" name="onglet" value="<?php echo e($onglet); ?>">
                            <button class="btn btn-sm btn-link p-0 <?php echo $lancee ? 'text-warning' : 'text-muted'; ?>">
                                <?php echo $lancee ? 'En cours — annuler le lancement' : 'Marquer comme lancé'; ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Classement de la poule -->
    <div class="col-xl-4">
        <div class="card position-sticky" style="top: 1rem;">
            <div class="card-header fw-semibold">Poule <?php echo e($poule['lettre']); ?></div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0 classement-poule">
                    <thead>
                        <tr class="text-muted small">
                            <th>Pl.</th><th>Joueur</th>
                            <th class="text-center">V</th>
                            <th class="text-center">Sets</th>
                            <th class="text-center">Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $st = $pdo->prepare(
                            'SELECT pp.lettre, pp.place, pp.victoires, pp.sets_pour, pp.sets_contre,'
                            . '       pp.ex_aequo, pp.groupe_egalite, pp.participation_id,'
                            . '       j.nom, j.prenom, c.code'
                            . '  FROM ' . table('poule_participant') . ' pp'
                            . '  JOIN ' . table('participation') . ' pa ON pa.id = pp.participation_id'
                            . '  JOIN ' . table('joueur') . ' j ON j.id = pa.joueur_id'
                            . '  JOIN ' . table('classement') . ' c ON c.id = pa.classement_id'
                            . ' WHERE pp.poule_id = ?'
                            . ' ORDER BY COALESCE(pp.place, 99), pp.lettre'
                        );
                        $st->execute([$pouleId]);
                        $lignes = $st->fetchAll();
                        ?>
                        <?php foreach ($lignes as $i => $l): ?>
                            <?php
                            $ex     = (int) $l['ex_aequo'] === 1;
                            $memeAv = $ex && isset($lignes[$i - 1])
                                && $lignes[$i - 1]['groupe_egalite'] === $l['groupe_egalite'];
                            $memeAp = $ex && isset($lignes[$i + 1])
                                && $lignes[$i + 1]['groupe_egalite'] === $l['groupe_egalite'];
                            ?>
                            <tr<?php echo $ex ? ' class="table-warning"' : ''; ?>>
                                <td class="text-muted"><?php echo $l['place'] !== null ? (int) $l['place'] : '—'; ?></td>
                                <td>
                                    <span class="lettre-poule"><?php echo e($l['lettre']); ?></span>
                                    <strong><?php echo e($l['nom']); ?></strong>
                                    <?php echo e($l['prenom']); ?>
                                    <span class="text-muted small"><?php echo e($l['code']); ?></span>
                                    <?php if ($memeAv || $memeAp): ?>
                                        <span class="fleches-egalite text-nowrap">
                                            <?php foreach ([['monter_poule', $memeAv, 'up'], ['descendre_poule', $memeAp, 'down']] as [$act, $actif, $ico]): ?>
                                                <?php if ($actif): ?>
                                                    <form method="post" class="d-inline">
                                                        <?php echo champCsrf(); ?>
                                                        <input type="hidden" name="action" value="<?php echo e($act); ?>">
                                                        <input type="hidden" name="poule" value="<?php echo $pouleId; ?>">
                                                        <input type="hidden" name="participation" value="<?php echo (int) $l['participation_id']; ?>">
                                                        <input type="hidden" name="onglet" value="<?php echo e($onglet); ?>">
                                                        <button class="btn btn-sm btn-link p-0 text-muted">
                                                            <i class="bi bi-arrow-<?php echo $ico; ?>"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo (int) $l['victoires']; ?></td>
                                <td class="text-center small">
                                    <?php echo (int) $l['sets_pour']; ?>/<?php echo (int) $l['sets_contre']; ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $d = (int) $l['sets_pour'] - (int) $l['sets_contre'];
                                    printf('%s%d', $d > 0 ? '+' : '', $d);
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $egalites = false;

            foreach ($lignes as $l) {
                if ((int) $l['ex_aequo'] === 1) {
                    $egalites = true;
                }
            }
            ?>
            <?php if ($egalites): ?>
                <div class="card-footer small" style="background:#fff3cd;">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Aucun critère ne sépare les joueurs surlignés : à vous de trancher,
                    avec les flèches. Votre choix sera conservé tant que l'égalité subsiste.
                </div>
            <?php endif; ?>
            <div class="card-footer small text-muted">
                <?php if ($format->setsComparables()): ?>
                    Départage : victoires, différence de sets sur toute la poule,
                    puis confrontation directe.
                <?php else: ?>
                    Départage : victoires, puis sous-championnat entre joueurs à égalité.
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

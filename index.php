<?php

declare(strict_types=1);

/**
 * Gestion des tournois et challenges.
 *
 * Liste les tournois ; en deplier un affiche ses phases. Cette page ne
 * doit jamais produire d'erreur lorsqu'aucun tournoi n'existe :
 * l'organisateur doit pouvoir tout supprimer et repartir de zero.
 */

require __DIR__ . '/config/bootstrap.php';

exigerAccesGestion();

use RMCF\Tournois\Repository\PhaseRepository;
use RMCF\Tournois\Repository\TournoiRepository;

$pdo         = db();
$repoTournoi = new TournoiRepository($pdo);
$repoPhase   = new PhaseRepository($pdo);

// --- Traitement -------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierCsrf();

    try {
        switch ($_POST['action'] ?? '') {
            case 'creer':
                $phases = [];
                $noms   = (array) ($_POST['phase_libelle'] ?? []);
                $dates  = (array) ($_POST['phase_date'] ?? []);

                foreach ($noms as $i => $nom) {
                    $phases[] = [
                        'libelle' => (string) $nom,
                        'date'    => (string) ($dates[$i] ?? ''),
                    ];
                }

                $bareme = [];

                foreach ((array) ($_POST['bareme'] ?? []) as $cle => $points) {
                    $bareme[(string) $cle] = (int) $points;
                }

                $repoTournoi->creer(
                    (string) ($_POST['libelle'] ?? ''),
                    (string) ($_POST['saison'] ?? ''),
                    (string) ($_POST['type'] ?? 'poules'),
                    $phases,
                    $bareme
                );

                message('Tournoi créé.');
                break;

            case 'modifier_tournoi':
                $repoTournoi->modifier(
                    (int) ($_POST['tournoi'] ?? 0),
                    (string) ($_POST['libelle'] ?? ''),
                    (string) ($_POST['saison'] ?? ''),
                    (string) ($_POST['statut'] ?? 'en_cours')
                );
                message('Tournoi modifié.');
                break;

            case 'ajouter_phase':
                $repoTournoi->ajouterPhase(
                    (int) ($_POST['tournoi'] ?? 0),
                    (string) ($_POST['date'] ?? '')
                );
                message('Phase ajoutée.');
                break;

            case 'supprimer':
                $repoTournoi->supprimer((int) ($_POST['tournoi'] ?? 0));
                message('Tournoi supprimé. Les joueurs ont été conservés.');
                break;

            case 'modifier_phase':
                $repoTournoi->modifierPhase(
                    (int) ($_POST['phase'] ?? 0),
                    (string) ($_POST['libelle'] ?? ''),
                    (string) ($_POST['date'] ?? '')
                );
                message('Phase modifiée.');
                break;

            case 'supprimer_phase':
                $repoTournoi->supprimerPhase((int) ($_POST['phase'] ?? 0));
                message('Phase supprimée.');
                break;
        }
    } catch (Throwable $e) {
        message($e->getMessage(), 'danger');
    }

    $tournoiOuvert = (int) ($_POST['tournoi'] ?? 0);

    header('Location: ' . url('index.php' . ($tournoiOuvert > 0 ? '?tournoi=' . $tournoiOuvert : '')));
    exit;
}

// --- Affichage --------------------------------------------------------

$tournois = $repoTournoi->tous();
$ouvert   = (int) ($_GET['tournoi'] ?? 0);

if ($ouvert === 0 && $tournois !== []) {
    $ouvert = (int) $tournois[0]['id'];
}

/** Libelle et couleur d'un statut de phase. */
function etatPhase(string $statut): array
{
    return match ($statut) {
        'preparation' => ['En préparation', 'secondary'],
        'poules'      => ['Poules en cours', 'primary'],
        'barrage'     => ['Barrages',        'warning'],
        'tableaux'    => ['Tableaux',        'warning'],
        'terminee'    => ['Terminée',        'success'],
        default       => [$statut,           'secondary'],
    };
}

ouvrirPage('Gestion des tournois');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0" style="color: var(--primary-color);">
        <i class="bi bi-trophy me-2"></i>Gestion des tournois et challenges
    </h1>
    <div>
        <button class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#creation">
            <i class="bi bi-plus-lg me-1"></i>Nouveau tournoi
        </button>
    </div>
</div>

<!-- Création -->
<div class="collapse <?php echo $tournois === [] ? 'show' : ''; ?> mb-4" id="creation">
    <div class="card">
        <div class="card-header fw-semibold">Nouveau tournoi ou challenge</div>
        <div class="card-body" x-data="creation()">
            <form method="post">
                <?php echo champCsrf(); ?>
                <input type="hidden" name="action" value="creer">

                <div class="row g-3 mb-4">
                    <div class="col-md-5">
                        <label for="libelle" class="form-label">Nom</label>
                        <input type="text" name="libelle" id="libelle" class="form-control"
                               required maxlength="80" placeholder="Mickey By Night 2026">
                    </div>
                    <div class="col-md-3">
                        <label for="saison" class="form-label">Saison</label>
                        <input type="text" name="saison" id="saison" class="form-control"
                               maxlength="15" placeholder="2025-2026">
                    </div>
                    <div class="col-md-4">
                        <label for="type" class="form-label">Déroulement</label>
                        <select name="type" id="type" class="form-select" x-model="type">
                            <option value="poules">Poules puis tableaux</option>
                            <option value="elimination_directe">Élimination directe</option>
                        </select>
                    </div>
                </div>

                <!-- Phases -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">Phases</label>
                    <p class="text-muted small">
                        Un tournoi peut ne compter qu'une seule phase, ou plusieurs
                        soirées dont les points s'additionnent.
                    </p>

                    <div class="d-flex align-items-center gap-2 mb-3">
                        <label for="nbPhases" class="form-label mb-0">Nombre de phases</label>
                        <input type="number" id="nbPhases" class="form-control" style="width:5rem;"
                               min="1" max="12" x-model.number="nb" @input="ajuster()">
                    </div>

                    <template x-for="(p, i) in phases" :key="i">
                        <div class="row g-2 mb-2 align-items-end">
                            <div class="col-md-5">
                                <input type="text" name="phase_libelle[]" class="form-control"
                                       maxlength="60" x-model="p.libelle"
                                       :placeholder="'Phase ' + (i + 1)">
                            </div>
                            <div class="col-md-4">
                                <input type="date" name="phase_date[]" class="form-control"
                                       x-model="p.date">
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Barème -->
                <div class="mb-4" x-show="type === 'poules'">
                    <label class="form-label fw-semibold">Attribution des points</label>
                    <p class="text-muted small">
                        Valeurs reprises de la feuille ATTRIBUTION DES POINTS du classeur.
                        Un joueur totalise les points de participation, ceux de poule,
                        et une seule valeur de parcours — celle du stade où il s'est arrêté.
                    </p>

                    <div class="row g-2">
                        <?php foreach (TournoiRepository::BAREME_DEFAUT as [, , $cle, $defaut, $texte]): ?>
                            <div class="col-md-4 col-lg-3">
                                <label for="b<?php echo e($cle); ?>" class="form-label small mb-0">
                                    <?php echo e($texte); ?>
                                </label>
                                <input type="number" class="form-control form-control-sm"
                                       id="b<?php echo e($cle); ?>"
                                       name="bareme[<?php echo e($cle); ?>]"
                                       value="<?php echo (int) $defaut; ?>" min="0" max="999">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Créer le tournoi
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Liste des tournois -->
<?php if ($tournois === []): ?>

    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        Aucun tournoi enregistré. Utilisez le formulaire ci-dessus pour en créer un.
    </div>

<?php else: ?>

    <div class="list-group mb-4">
        <?php foreach ($tournois as $t): ?>
            <?php $estOuvert = (int) $t['id'] === $ouvert; ?>

            <div class="list-group-item px-3 py-2">
                <div class="d-flex justify-content-between align-items-center">
                    <a href="<?php echo e(url('index.php?tournoi=' . (int) $t['id'])); ?>"
                       class="text-decoration-none flex-grow-1">
                        <i class="bi bi-chevron-<?php echo $estOuvert ? 'down' : 'right'; ?> me-2 text-muted"></i>
                        <strong style="color: var(--primary-color);"><?php echo e($t['libelle']); ?></strong>
                        <?php if ($t['saison']): ?>
                            <span class="text-muted small ms-2"><?php echo e($t['saison']); ?></span>
                        <?php endif; ?>
                        <span class="badge bg-light text-dark ms-2">
                            <?php echo (int) $t['nb_phases']; ?> phase<?php echo $t['nb_phases'] > 1 ? 's' : ''; ?>
                        </span>
                        <span class="text-muted small ms-2">
                            <?php echo $t['type'] === 'poules' ? 'Poules' : 'Élimination directe'; ?>
                        </span>
                    </a>

                    <div class="ms-3 text-nowrap">
                        <button class="btn btn-sm btn-outline-secondary"
                                data-bs-toggle="collapse"
                                data-bs-target="#et<?php echo (int) $t['id']; ?>"
                                title="Modifier le tournoi">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Supprimer « <?php echo e($t['libelle']); ?> » et toutes ses phases ? Les joueurs seront conservés.');">
                            <?php echo champCsrf(); ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="tournoi" value="<?php echo (int) $t['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Supprimer le tournoi">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Modification du tournoi -->
                <div class="collapse mt-3" id="et<?php echo (int) $t['id']; ?>">
                    <div class="row g-3">
                        <div class="col-lg-8">
                            <form method="post" class="row g-2 align-items-end">
                                <?php echo champCsrf(); ?>
                                <input type="hidden" name="action" value="modifier_tournoi">
                                <input type="hidden" name="tournoi" value="<?php echo (int) $t['id']; ?>">

                                <div class="col-sm-5">
                                    <label class="form-label small mb-0">Nom</label>
                                    <input type="text" name="libelle" class="form-control form-control-sm"
                                           required maxlength="80" value="<?php echo e($t['libelle']); ?>">
                                </div>
                                <div class="col-sm-3">
                                    <label class="form-label small mb-0">Saison</label>
                                    <input type="text" name="saison" class="form-control form-control-sm"
                                           maxlength="15" value="<?php echo e($t['saison'] ?? ''); ?>">
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label small mb-0">État</label>
                                    <div class="input-group input-group-sm">
                                        <select name="statut" class="form-select form-select-sm">
                                            <?php foreach ([
                                                'preparation' => 'En préparation',
                                                'en_cours'    => 'En cours',
                                                'termine'     => 'Terminé',
                                                'archive'     => 'Archivé',
                                            ] as $cle => $texte): ?>
                                                <option value="<?php echo e($cle); ?>"
                                                    <?php echo $t['statut'] === $cle ? 'selected' : ''; ?>>
                                                    <?php echo e($texte); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-primary">Enregistrer</button>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <div class="col-lg-4">
                            <form method="post" class="row g-2 align-items-end">
                                <?php echo champCsrf(); ?>
                                <input type="hidden" name="action" value="ajouter_phase">
                                <input type="hidden" name="tournoi" value="<?php echo (int) $t['id']; ?>">
                                <div class="col-7">
                                    <label class="form-label small mb-0">Nouvelle phase</label>
                                    <input type="date" name="date" class="form-control form-control-sm">
                                </div>
                                <div class="col-5">
                                    <button class="btn btn-sm btn-outline-primary w-100">
                                        <i class="bi bi-plus-lg me-1"></i>Ajouter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php if ($estOuvert): ?>
                    <?php $phases = $repoPhase->phases((int) $t['id']); ?>

                    <div class="table-responsive mt-3">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Phase</th>
                                    <th>Date</th>
                                    <th>État</th>
                                    <th class="text-center">Joueurs</th>
                                    <th class="text-center">Matchs</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($phases as $p): ?>
                                    <?php [$libelleEtat, $couleur] = etatPhase((string) $p['statut']); ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($p['libelle'] ?? ('Phase ' . $p['numero'])); ?></td>
                                        <td>
                                            <?php echo $p['date_phase']
                                                ? formatDate($p['date_phase'])
                                                : '<span class="text-muted">non fixée</span>'; ?>
                                        </td>
                                        <td><span class="badge bg-<?php echo $couleur; ?>"><?php echo e($libelleEtat); ?></span></td>
                                        <td class="text-center">
                                            <?php echo (int) $p['nb_pointes'] > 0
                                                ? (int) $p['nb_pointes']
                                                : '<span class="text-muted">—</span>'; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ((int) $p['nb_matchs'] > 0): ?>
                                                <?php echo (int) $p['nb_encodes']; ?> / <?php echo (int) $p['nb_matchs']; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button class="btn btn-sm btn-outline-secondary"
                                                    data-bs-toggle="collapse"
                                                    data-bs-target="#ed<?php echo (int) $p['id']; ?>"
                                                    title="Modifier">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <a href="<?php echo e(url('phase.php?phase=' . (int) $p['id'])); ?>"
                                               class="btn btn-sm btn-primary">
                                                Accès à la phase <i class="bi bi-arrow-right ms-1"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <tr class="collapse" id="ed<?php echo (int) $p['id']; ?>">
                                        <td colspan="6" class="bg-light">
                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-8">
                                                    <form method="post" class="row g-2 align-items-end">
                                                        <?php echo champCsrf(); ?>
                                                        <input type="hidden" name="action" value="modifier_phase">
                                                        <input type="hidden" name="phase" value="<?php echo (int) $p['id']; ?>">
                                                        <div class="col-sm-5">
                                                            <label class="form-label small mb-0">Nom</label>
                                                            <input type="text" name="libelle" class="form-control form-control-sm"
                                                                   maxlength="60"
                                                                   value="<?php echo e($p['libelle'] ?? ''); ?>">
                                                        </div>
                                                        <div class="col-sm-4">
                                                            <label class="form-label small mb-0">Date</label>
                                                            <input type="date" name="date" class="form-control form-control-sm"
                                                                   value="<?php echo e($p['date_phase'] ?? ''); ?>">
                                                        </div>
                                                        <div class="col-sm-3">
                                                            <button class="btn btn-sm btn-primary w-100">Enregistrer</button>
                                                        </div>
                                                    </form>
                                                </div>
                                                <div class="col-md-4 text-md-end">
                                                    <form method="post"
                                                          onsubmit="return confirm('Supprimer cette phase et tout son contenu ?');">
                                                        <?php echo champCsrf(); ?>
                                                        <input type="hidden" name="action" value="supprimer_phase">
                                                        <input type="hidden" name="phase" value="<?php echo (int) $p['id']; ?>">
                                                        <button class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-trash me-1"></i>Supprimer la phase
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if ($phases === []): ?>
                                    <tr>
                                        <td colspan="6" class="text-muted text-center">
                                            Ce tournoi ne comporte aucune phase.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<script>
function creation() {
    return {
        type: 'poules',
        nb: 1,
        phases: [{ libelle: '', date: '' }],

        ajuster() {
            const n = Math.max(1, Math.min(12, this.nb || 1));
            while (this.phases.length < n) this.phases.push({ libelle: '', date: '' });
            while (this.phases.length > n) this.phases.pop();
        }
    };
}
</script>

<?php
fermerPage(['https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js']);

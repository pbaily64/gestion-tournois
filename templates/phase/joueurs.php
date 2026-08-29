<?php
/**
 * Onglet « Liste des joueurs ».
 *
 * A gauche les joueurs disponibles, a droite ceux qui participent a
 * cette phase. Un clic bascule de l'un a l'autre. En dessous, la
 * creation d'un joueur inconnu et la modification d'un classement.
 *
 * @var int  $phaseId
 * @var bool $composees
 */

declare(strict_types=1);


$joueurs     = $repoJoueur->tousActifs();
$pointesIds  = $repoPart->joueursPointes($phaseId);
$classements = $repoClsmt->tous();

$donnees = array_map(
    static fn (array $j): array => [
        'id'            => (int) $j['id'],
        'nom'           => $j['nom'],
        'prenom'        => $j['prenom'],
        'classement'    => $j['classement'],
        'classement_id' => (int) $j['classement_id'],
        'rang'          => (int) $j['rang'],
        'pointe'        => in_array((int) $j['id'], $pointesIds, true),
    ],
    $joueurs
);

?>

<div x-data="listeJoueurs(<?php echo e(json_encode($donnees, JSON_UNESCAPED_UNICODE)); ?>)">

    <?php if ($composees): ?>
        <div class="alert alert-secondary">
            <i class="bi bi-lock me-1"></i>
            Les poules sont composées : la liste des joueurs est figée.
            Supprimez les poules depuis l'onglet « Tableau des poules » pour la modifier.
        </div>
    <?php endif; ?>

    <div class="row g-3">

        <!-- Joueurs disponibles -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Liste des joueurs disponibles</span>
                    <span class="badge bg-secondary" x-text="disponibles.length"></span>
                </div>
                <div class="card-body">
                    <input type="text" class="form-control mb-3" placeholder="Rechercher…" x-model="filtre">
                    <ul class="list-group liste-pointage">
                        <template x-for="j in disponibles" :key="j.id">
                            <li class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                                @click="<?php echo $composees ? '' : 'ajouter(j)'; ?>"
                                style="<?php echo $composees ? '' : 'cursor:pointer;'; ?>">
                                <span>
                                    <strong x-text="j.nom"></strong>
                                    <span class="text-muted" x-text="j.prenom"></span>
                                </span>
                                <span>
                                    <span class="badge bg-light text-dark me-2" x-text="j.classement"></span>
                                    <?php if (!$composees): ?>
                                        <i class="bi bi-arrow-right text-success"></i>
                                    <?php endif; ?>
                                </span>
                            </li>
                        </template>
                        <li class="list-group-item text-muted text-center" x-show="disponibles.length === 0">
                            Aucun joueur disponible
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Joueurs de la phase -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Joueurs de cette phase</span>
                    <span class="badge bg-primary" x-text="pointes.length"></span>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Ordre du serpentin : classement décroissant, puis alphabétique.
                    </p>
                    <ul class="list-group liste-pointage">
                        <template x-for="(j, i) in pointesTries" :key="j.id">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span <?php echo $composees ? '' : '@click="retirer(j)" style="cursor:pointer;"'; ?>>
                                    <span class="text-muted me-2" x-text="(i + 1) + '.'"></span>
                                    <strong x-text="j.nom"></strong>
                                    <span class="text-muted" x-text="j.prenom"></span>
                                </span>
                                <span class="text-nowrap">
                                    <button type="button" class="btn btn-sm btn-link p-0 me-2"
                                            @click="editer(j)" title="Modifier le classement">
                                        <span class="badge bg-light text-dark" x-text="j.classement"></span>
                                    </button>
                                    <?php if (!$composees): ?>
                                        <i class="bi bi-x-lg text-danger" @click="retirer(j)"
                                           style="cursor:pointer;"></i>
                                    <?php endif; ?>
                                </span>
                            </li>
                        </template>
                        <li class="list-group-item text-muted text-center" x-show="pointes.length === 0">
                            Aucun joueur dans cette phase
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$composees): ?>
        <form method="post" class="my-3">
            <?php echo champCsrf(); ?>
            <input type="hidden" name="action" value="pointage">
            <input type="hidden" name="onglet" value="joueurs">
            <template x-for="j in pointes" :key="'p' + j.id">
                <input type="hidden" name="pointes[]" :value="j.id">
            </template>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1"></i>Enregistrer la liste
            </button>
            <span class="text-muted small ms-2" x-show="modifie">Modifications non enregistrées</span>
        </form>

        <!-- Nouveau joueur -->
        <div class="card mb-3">
            <div class="card-header fw-semibold">
                <i class="bi bi-person-plus me-1"></i>Nouveau joueur
            </div>
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <?php echo champCsrf(); ?>
                    <input type="hidden" name="action" value="nouveau_joueur">
                    <input type="hidden" name="onglet" value="joueurs">
                    <template x-for="j in pointes" :key="'n' + j.id">
                        <input type="hidden" name="pointes[]" :value="j.id">
                    </template>

                    <div class="col-md-4">
                        <label for="nom" class="form-label">Nom</label>
                        <input type="text" name="nom" id="nom" class="form-control" required maxlength="60">
                    </div>
                    <div class="col-md-4">
                        <label for="prenom" class="form-label">Prénom</label>
                        <input type="text" name="prenom" id="prenom" class="form-control" required maxlength="60">
                    </div>
                    <div class="col-md-2">
                        <label for="classement_id" class="form-label">Classement</label>
                        <select name="classement_id" id="classement_id" class="form-select" required>
                            <?php foreach ($classements as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>"
                                    <?php echo $c['code'] === 'NC' ? 'selected' : ''; ?>>
                                    <?php echo e($c['code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-plus-lg me-1"></i>Ajouter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Génération des poules -->
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="bi bi-diagram-3 me-1"></i>Générer les poules
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Moins de poules signifie plus de matchs par joueur, donc une soirée
                    plus longue ; davantage de poules raccourcit la soirée mais chacun
                    joue moins. Chaque poule doit compter de 3 à 8 joueurs.
                </p>

                <form method="post">
                    <?php echo champCsrf(); ?>
                    <input type="hidden" name="action" value="composer">
                    <input type="hidden" name="onglet" value="composition">

                    <!-- La liste courante part avec la demande : les poules
                         sont composées sur ce qui est à l'écran, jamais sur
                         un enregistrement antérieur. -->
                    <template x-for="j in pointes" :key="'g' + j.id">
                        <input type="hidden" name="pointes[]" :value="j.id">
                    </template>

                    <div class="d-flex align-items-end gap-3 mb-3 flex-wrap">
                        <div>
                            <label for="nbPoules" class="form-label mb-1">Nombre de poules</label>
                            <input type="number" id="nbPoules" name="nb_poules"
                                   class="form-control" style="width:6rem;"
                                   min="2" max="8" x-model.number="nbPoules" required>
                        </div>

                        <div class="flex-grow-1">
                            <template x-if="repartition">
                                <div>
                                    <div class="fw-semibold" x-text="repartition.composition"></div>
                                    <div class="text-muted small">
                                        <span x-text="repartition.nbMatchs"></span> matchs au total,
                                        <span x-text="repartition.parJoueur"></span> par joueur
                                    </div>
                                </div>
                            </template>
                            <template x-if="!repartition">
                                <div class="text-danger small" x-text="raisonImpossible"></div>
                            </template>
                        </div>

                        <button type="submit" class="btn btn-primary" :disabled="!repartition">
                            <i class="bi bi-shuffle me-1"></i>Générer les poules
                        </button>
                    </div>

                    <!-- Répartitions possibles pour l'effectif courant -->
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="width:5rem;">Poules</th>
                                    <th>Répartition</th>
                                    <th class="text-center">Matchs</th>
                                    <th class="text-center">Par joueur</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="o in options" :key="o.nbPoules">
                                    <tr @click="nbPoules = o.nbPoules" style="cursor:pointer;"
                                        :class="o.nbPoules === nbPoules ? 'table-primary' : ''">
                                        <td class="fw-semibold" x-text="o.nbPoules"></td>
                                        <td x-text="o.composition"></td>
                                        <td class="text-center">
                                            <span class="badge bg-primary" x-text="o.nbMatchs"></span>
                                        </td>
                                        <td class="text-center" x-text="o.parJoueur"></td>
                                    </tr>
                                </template>
                                <tr x-show="options.length === 0">
                                    <td colspan="4" class="text-muted text-center">
                                        Aucune répartition possible avec
                                        <span x-text="pointes.length"></span> joueur(s).
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modification d'un joueur -->
    <div class="modal fade" id="modalJoueur" tabindex="-1" x-ref="modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <?php echo champCsrf(); ?>
                    <input type="hidden" name="action" value="modifier_joueur">
                    <input type="hidden" name="onglet" value="joueurs">
                    <input type="hidden" name="joueur" :value="edition.id">

                    <div class="modal-header">
                        <h5 class="modal-title">Modifier le joueur</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small">
                            Le classement peut changer d'une saison à l'autre, ou avoir été
                            annoncé de façon erronée. Les phases déjà terminées conservent
                            leurs résultats.
                        </p>
                        <div class="mb-2">
                            <label class="form-label">Nom</label>
                            <input type="text" name="nom" class="form-control" x-model="edition.nom" required maxlength="60">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Prénom</label>
                            <input type="text" name="prenom" class="form-control" x-model="edition.prenom" required maxlength="60">
                        </div>
                        <div>
                            <label class="form-label">Classement</label>
                            <select name="classement_id" class="form-select" x-model="edition.classement_id">
                                <?php foreach ($classements as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>"><?php echo e($c['code']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function listeJoueurs(joueurs) {
    return {
        joueurs: joueurs,
        filtre: '',
        modifie: false,
        nbPoules: 4,
        edition: { id: 0, nom: '', prenom: '', classement_id: 0 },
        initial: joueurs.filter(j => j.pointe).map(j => j.id).sort().join(','),

        get disponibles() {
            const f = this.filtre.trim().toLowerCase();
            return this.joueurs
                .filter(j => !j.pointe)
                .filter(j => f === '' || (j.nom + ' ' + j.prenom).toLowerCase().includes(f));
        },

        get pointes() {
            return this.joueurs.filter(j => j.pointe);
        },

        get pointesTries() {
            return [...this.pointes].sort((a, b) =>
                b.rang - a.rang ||
                a.nom.localeCompare(b.nom, 'fr') ||
                a.prenom.localeCompare(b.prenom, 'fr')
            );
        },

        controler() {
            this.modifie = this.pointes.map(j => j.id).sort().join(',') !== this.initial;
        },

        ajouter(j) { j.pointe = true;  this.controler(); },
        retirer(j) { j.pointe = false; this.controler(); },

        // --- Repartition en poules -------------------------------
        // Reproduit RepartitionPoules cote navigateur, pour que les
        // options suivent la selection en cours et non le dernier
        // enregistrement. Le serveur revalide de toute facon.

        tailles(n, p) {
            if (p < 2 || p > 8 || n < 1) return null;

            const base = Math.floor(n / p);
            const reste = n % p;
            const t = Array(reste).fill(base + 1).concat(Array(p - reste).fill(base));

            if (Math.min(...t) < 3 || Math.max(...t) > 8) return null;

            return t;
        },

        decrire(t) {
            const compte = {};
            t.forEach(x => compte[x] = (compte[x] || 0) + 1);

            return Object.keys(compte)
                .map(Number)
                .sort((a, b) => b - a)
                .map(taille => {
                    const nb = compte[taille];
                    return nb === 1 ? `1 poule de ${taille}` : `${nb} poules de ${taille}`;
                })
                .join(' + ');
        },

        detailler(p) {
            const t = this.tailles(this.pointes.length, p);
            if (!t) return null;

            const nbMatchs = t.reduce((s, x) => s + (x * (x - 1)) / 2, 0);
            const mn = Math.min(...t) - 1;
            const mx = Math.max(...t) - 1;

            return {
                nbPoules: p,
                composition: this.decrire(t),
                nbMatchs: nbMatchs,
                parJoueur: mn === mx ? String(mn) : `${mn} à ${mx}`
            };
        },

        get options() {
            const out = [];
            for (let p = 2; p <= 8; p++) {
                const d = this.detailler(p);
                if (d) out.push(d);
            }
            return out;
        },

        get repartition() {
            return this.detailler(this.nbPoules);
        },

        get raisonImpossible() {
            const n = this.pointes.length;
            if (n < 6) return `${n} joueur(s) : il en faut au moins 6.`;
            if (n > 64) return `${n} joueurs : le maximum est 64.`;
            return `${this.nbPoules} poules ne conviennent pas à ${n} joueurs `
                 + `(chaque poule doit compter de 3 à 8 joueurs).`;
        },

        editer(j) {
            this.edition = { id: j.id, nom: j.nom, prenom: j.prenom, classement_id: j.classement_id };
            new bootstrap.Modal(this.$refs.modal).show();
        }
    };
}
</script>

<?php
/**
 * Onglet « Tableau des poules ».
 *
 * Affiche toutes les poules cote a cote. Tant que les rencontres ne
 * sont pas creees, deux joueurs de poules differentes peuvent etre
 * echanges — par glisser-deposer ou par deux clics. C'est une decision
 * de l'organisateur, qui evite par exemple que deux joueurs se
 * rencontrent de semaine en semaine ; rien n'est automatique.
 *
 * L'echange preserve la taille de chaque poule, contrairement a un
 * simple deplacement.
 *
 * @var list<array> $poules
 * @var bool        $validees
 * @var int         $phaseId
 */

declare(strict_types=1);
?>

<?php if (!$validees): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-1"></i>
        Ajustez la composition si nécessaire, puis validez : l'ordre des matchs
        et les handicaps seront alors calculés. Faites glisser un joueur sur un
        autre pour les échanger, ou cliquez sur deux joueurs de poules
        différentes.
    </div>
<?php endif; ?>

<div x-data="composition()">

    <div class="row g-3 mb-4">
        <?php foreach ($poules as $p): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100">
                    <div class="card-header fw-semibold d-flex justify-content-between">
                        <span>Poule <?php echo e($p['lettre']); ?></span>
                        <span class="badge bg-secondary"><?php echo count($p['membres']); ?></span>
                    </div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($p['membres'] as $m): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center joueur-poule"
                                <?php if (!$validees): ?>
                                    draggable="true"
                                    data-id="<?php echo (int) $m['participation_id']; ?>"
                                    data-poule="<?php echo (int) $p['id']; ?>"
                                    @click="choisir(<?php echo (int) $m['participation_id']; ?>, <?php echo (int) $p['id']; ?>)"
                                    @dragstart="debut($event, <?php echo (int) $m['participation_id']; ?>, <?php echo (int) $p['id']; ?>)"
                                    @dragover.prevent="survol($event, <?php echo (int) $p['id']; ?>)"
                                    @dragleave="$event.currentTarget.classList.remove('cible')"
                                    @drop.prevent="deposer($event, <?php echo (int) $m['participation_id']; ?>, <?php echo (int) $p['id']; ?>)"
                                    :class="selection === <?php echo (int) $m['participation_id']; ?> ? 'selectionne' : ''"
                                    style="cursor:grab;"
                                <?php endif; ?>>
                                <span>
                                    <span class="lettre-poule"><?php echo e($m['lettre']); ?></span>
                                    <strong><?php echo e($m['nom']); ?></strong>
                                    <span class="text-muted"><?php echo e($m['prenom']); ?></span>
                                </span>
                                <span class="badge bg-light text-dark"><?php echo e($m['classement']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Échange -->
    <form method="post" id="fEchange" class="d-none">
        <?php echo champCsrf(); ?>
        <input type="hidden" name="action" value="echanger">
        <input type="hidden" name="onglet" value="composition">
        <input type="hidden" name="a" x-ref="a">
        <input type="hidden" name="b" x-ref="b">
    </form>

    <?php if (!$validees): ?>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <form method="post" class="d-inline">
                <?php echo champCsrf(); ?>
                <input type="hidden" name="action" value="valider_poules">
                <input type="hidden" name="onglet" value="composition">
                <button class="btn btn-primary btn-lg">
                    <i class="bi bi-check2-circle me-1"></i>Valider les poules
                </button>
            </form>

            <form method="post" class="d-inline"
                  onsubmit="return confirm('Supprimer les poules et revenir à la liste des joueurs ?');">
                <?php echo champCsrf(); ?>
                <input type="hidden" name="action" value="supprimer_poules">
                <input type="hidden" name="onglet" value="joueurs">
                <button class="btn btn-outline-danger">
                    <i class="bi bi-trash me-1"></i>Supprimer les poules
                </button>
            </form>

            <span class="text-muted small" x-show="selection">
                Joueur sélectionné : cliquez sur un joueur d'une autre poule pour échanger.
            </span>
        </div>
    <?php else: ?>
        <form method="post"
              onsubmit="return confirm('Supprimer les poules, les rencontres et les résultats encodés ?');">
            <?php echo champCsrf(); ?>
            <input type="hidden" name="action" value="supprimer_poules">
            <input type="hidden" name="onglet" value="joueurs">
            <button class="btn btn-outline-danger">
                <i class="bi bi-trash me-1"></i>Supprimer les poules
            </button>
            <span class="text-muted small ms-2">
                Les résultats déjà encodés seront perdus.
            </span>
        </form>
    <?php endif; ?>
</div>

<script>
function composition() {
    return {
        selection: null,
        poule: null,

        // --- Sélection par clic, indispensable sur tablette ---------
        choisir(id, poule) {
            if (this.selection === null) {
                this.selection = id;
                this.poule = poule;
                return;
            }

            if (this.selection === id) {
                this.selection = null;
                return;
            }

            if (this.poule === poule) {
                // Même poule : l'échange n'aurait aucun effet.
                this.selection = id;
                this.poule = poule;
                return;
            }

            this.echanger(this.selection, id);
        },

        // --- Glisser-déposer ---------------------------------------
        debut(e, id, poule) {
            this.selection = id;
            this.poule = poule;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(id));
            e.currentTarget.classList.add('en-deplacement');
        },

        survol(e, poule) {
            if (this.poule !== null && this.poule !== poule) {
                e.currentTarget.classList.add('cible');
            }
        },

        deposer(e, id, poule) {
            e.currentTarget.classList.remove('cible');
            document.querySelectorAll('.en-deplacement')
                .forEach(el => el.classList.remove('en-deplacement'));

            if (this.selection === null || this.selection === id || this.poule === poule) {
                this.selection = null;
                return;
            }

            this.echanger(this.selection, id);
        },

        echanger(a, b) {
            this.$refs.a.value = a;
            this.$refs.b.value = b;
            document.getElementById('fEchange').submit();
        }
    };
}
</script>

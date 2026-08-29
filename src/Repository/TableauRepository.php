<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RMCF\Tournois\Domain\FormatMatch;
use RMCF\Tournois\Domain\Qualification;
use RMCF\Tournois\Domain\Tableau;
use RuntimeException;
use Throwable;

/**
 * Barrages et tableaux a elimination directe.
 *
 * La generation cree d'un coup toutes les rencontres : celles du
 * barrage, celles du tableau final, celles de la consolante. Les tours
 * au-dela du premier existent sans participants ; ils se remplissent au
 * fur et a mesure des resultats.
 *
 * Trois mecaniques cohabitent :
 *
 *   - PLACEMENT : les qualifies directs prennent position selon l'ordre
 *     de la feuille Tab_Final, la tete de serie 1 face a la seizieme.
 *
 *   - BARRAGE : les places du bas de la cible restent vides jusqu'a ce
 *     que le barrage les attribue. Le vainqueur rejoint la position
 *     correspondant a la place qu'il vient de gagner.
 *
 *   - FORFAIT : une position sans joueur — lorsque l'effectif n'atteint
 *     pas seize — fait passer l'adversaire au tour suivant sans jouer.
 */
final class TableauRepository
{
    /**
     * Poule d'origine de chaque place, renseignee par poursuivants().
     *
     * @var array<int,?string>
     */
    private array $poules = [];

    public function __construct(private PDO $pdo)
    {
    }

    /** Les tableaux ont-ils deja ete generes ? */
    public function existent(int $phaseId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . table('rencontre')
            . " WHERE phase_id = ? AND contexte <> 'poule'"
        );
        $st->execute([$phaseId]);

        return (int) $st->fetchColumn() > 0;
    }

    /**
     * Joueurs poursuivant la phase, dans l'ordre du classement general.
     *
     * @return array<int,int> place => participation_id
     */
    public function poursuivants(int $phaseId): array
    {
        $st = $this->pdo->prepare(
            'SELECT pa.id, po.lettre AS poule FROM ' . table('participation') . ' pa'
            . '  LEFT JOIN ' . table('poule_participant') . ' pp ON pp.participation_id = pa.id'
            . '  LEFT JOIN ' . table('poule') . ' po ON po.id = pp.poule_id'
            . ' WHERE pa.phase_id = ? AND pa.place_generale IS NOT NULL'
            . '   AND (pa.poursuit IS NULL OR pa.poursuit = 1)'
            . ' ORDER BY pa.place_generale'
        );
        $st->execute([$phaseId]);

        $out  = [];
        $rang = 0;

        // Les places sont renumerotees : un joueur qui s'arrete ne doit
        // pas laisser un trou dans le tableau.
        foreach ($st->fetchAll() as $l) {
            $rang++;
            $out[$rang]           = (int) $l['id'];
            $this->poules[$rang]  = $l['poule'] !== null ? (string) $l['poule'] : null;
        }

        return $out;
    }

    /** Etat previsionnel, avant generation. */
    public function previsionnel(int $phaseId, bool $avecConsolation): Qualification
    {
        return Qualification::pour(count($this->poursuivants($phaseId)), $avecConsolation);
    }

    /**
     * Genere barrage, tableau final et consolante.
     *
     * @return array{barrages:int, matchs:int}
     * @throws RuntimeException
     */
    public function generer(int $phaseId, bool $avecConsolation): array
    {
        if ($this->existent($phaseId)) {
            throw new RuntimeException(
                'Les tableaux de cette phase existent deja. Supprimez-les avant d\'en generer de nouveaux.'
            );
        }

        $joueurs = $this->poursuivants($phaseId);
        $q       = Qualification::pour(count($joueurs), $avecConsolation);

        if (count($joueurs) < 2) {
            throw new RuntimeException('Il faut au moins deux joueurs pour ouvrir un tableau.');
        }

        $this->pdo->beginTransaction();

        try {
            $nbBarrages = $this->creerBarrages($phaseId, $q, $joueurs);

            $nbMatchs = $this->creerTableau($phaseId, 'tableau_final', 0, $q, $joueurs);

            if ($q->placesConsolation() !== []) {
                $nbMatchs += $this->creerTableau($phaseId, 'consolation', Tableau::POSITIONS, $q, $joueurs);
            }

            $st = $this->pdo->prepare(
                'UPDATE ' . table('phase') . " SET statut = 'tableaux', avec_consolation = ? WHERE id = ?"
            );
            $st->execute([$avecConsolation ? 1 : 0, $phaseId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Les forfaits se propagent une fois tout en place.
        $this->propagerForfaits($phaseId);

        return ['barrages' => $nbBarrages, 'matchs' => $nbMatchs];
    }

    /** Supprime barrages et tableaux, sans toucher aux poules. */
    public function supprimer(int $phaseId): void
    {
        $this->pdo->beginTransaction();

        try {
            $st = $this->pdo->prepare(
                'DELETE m FROM ' . table('manche') . ' m'
                . '  JOIN ' . table('rencontre') . ' r ON r.id = m.rencontre_id'
                . " WHERE r.phase_id = ? AND r.contexte <> 'poule'"
            );
            $st->execute([$phaseId]);

            $st = $this->pdo->prepare(
                'DELETE FROM ' . table('rencontre') . " WHERE phase_id = ? AND contexte <> 'poule'"
            );
            $st->execute([$phaseId]);

            $st = $this->pdo->prepare(
                'UPDATE ' . table('phase') . " SET statut = 'barrage' WHERE id = ?"
            );
            $st->execute([$phaseId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Rencontres d'un tableau, regroupees par tour.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public function parTour(int $phaseId, string $contexte): array
    {
        $sql = 'SELECT r.id, r.contexte, r.tour, r.ordre, r.handicap, r.sets_1, r.sets_2, r.vainqueur,'
             . '       r.lancee_le, r.place_1, r.place_2, r.place_attribuee,'
             . '       j1.nom AS nom_1, j1.prenom AS prenom_1, c1.code AS classement_1,'
             . '       po1.lettre AS poule_1,'
             . '       j2.nom AS nom_2, j2.prenom AS prenom_2, c2.code AS classement_2,'
             . '       po2.lettre AS poule_2'
             . '  FROM ' . table('rencontre') . ' r'
             . '  LEFT JOIN ' . table('participation') . ' p1 ON p1.id = r.participation_1_id'
             . '  LEFT JOIN ' . table('joueur') . ' j1 ON j1.id = p1.joueur_id'
             . '  LEFT JOIN ' . table('classement') . ' c1 ON c1.id = p1.classement_id'
             . '  LEFT JOIN ' . table('poule_participant') . ' pp1 ON pp1.participation_id = p1.id'
             . '  LEFT JOIN ' . table('poule') . ' po1 ON po1.id = pp1.poule_id'
             . '  LEFT JOIN ' . table('participation') . ' p2 ON p2.id = r.participation_2_id'
             . '  LEFT JOIN ' . table('joueur') . ' j2 ON j2.id = p2.joueur_id'
             . '  LEFT JOIN ' . table('classement') . ' c2 ON c2.id = p2.classement_id'
             . '  LEFT JOIN ' . table('poule_participant') . ' pp2 ON pp2.participation_id = p2.id'
             . '  LEFT JOIN ' . table('poule') . ' po2 ON po2.id = pp2.poule_id'
             . ' WHERE r.phase_id = ? AND r.contexte = ?'
             . ' ORDER BY r.id';

        $st = $this->pdo->prepare($sql);
        $st->execute([$phaseId, $contexte]);

        $out = [];

        foreach ($st->fetchAll() as $r) {
            $out[(string) ($r['tour'] ?? 'barrage')][] = $r;
        }

        return $out;
    }

    /**
     * Reporte le vainqueur d'une rencontre de tableau ou de barrage.
     *
     * A appeler apres chaque encodage : c'est ce qui fait avancer le
     * tableau sans intervention.
     */
    public function propager(int $rencontreId): void
    {
        $r = $this->rencontre($rencontreId);

        if ($r === null || $r['vainqueur'] === null || $r['contexte'] === 'poule') {
            return;
        }

        $gagnant = (int) ($r['vainqueur'] === '1' ? $r['participation_1_id'] : $r['participation_2_id']);

        if ($gagnant === 0) {
            return;
        }

        if ($r['contexte'] === 'barrage') {
            $this->placerBarragiste((int) $r['phase_id'], $gagnant, (int) $r['place_attribuee']);
            return;
        }

        $suite = Tableau::destination((string) $r['tour'], (int) $r['ordre']);

        if ($suite === null) {
            return; // finale : plus rien apres
        }

        $this->placer(
            (int) $r['phase_id'],
            (string) $r['contexte'],
            $suite['tour'],
            $suite['match'],
            $suite['cote'],
            $gagnant
        );
    }

    // -----------------------------------------------------------------
    //  Generation
    // -----------------------------------------------------------------

    /** @param array<int,int> $joueurs */
    private function creerBarrages(int $phaseId, Qualification $q, array $joueurs): int
    {
        if (!$q->avecBarrage()) {
            return 0;
        }

        $ordre = 0;

        foreach ($q->barrages as [$meilleur, $moinsBon]) {
            $ordre++;

            $this->inserer($phaseId, 'barrage', null, $ordre, [
                'p1'    => $joueurs[$meilleur] ?? null,
                'p2'    => $joueurs[$moinsBon] ?? null,
                'pl1'   => $meilleur,
                'pl2'   => $moinsBon,
                'place' => $q->cible - $q->excedent + $ordre,
            ]);
        }

        return $ordre;
    }

    /**
     * Cree les quinze rencontres d'un tableau : huit huitiemes, quatre
     * quarts, deux demies, une finale.
     *
     * @param  array<int,int> $joueurs
     */
    private function creerTableau(
        int $phaseId,
        string $contexte,
        int $decalage,
        Qualification $q,
        array $joueurs
    ): int {
        $premier = Tableau::premierTour($decalage);
        $total   = 0;

        // Places attribuees par le barrage : laissees vides ici.
        $enAttente = $q->avecBarrage() ? $q->barragistes() : [];
        $reservees = [];

        foreach ($q->barrages as $i => $couple) {
            $reservees[] = $q->cible - $q->excedent + $i + 1;
        }

        // Le placement officiel est applique tel quel. Les
        // affrontements entre joueurs d'une meme poule sont signales a
        // l'ecran, et l'organisateur les corrige a la main : c'est une
        // decision sportive, pas un calcul.
        foreach ($premier as $i => [$placeA, $placeB]) {
            $this->inserer($phaseId, $contexte, '8e', $i + 1, [
                'p1'  => in_array($placeA, $reservees, true) ? null : ($joueurs[$placeA] ?? null),
                'p2'  => in_array($placeB, $reservees, true) ? null : ($joueurs[$placeB] ?? null),
                'pl1' => $placeA,
                'pl2' => $placeB,
            ]);
            $total++;
        }

        foreach (['quart', 'demie', 'finale'] as $tour) {
            for ($i = 1; $i <= Tableau::nombreDeMatchs($tour); $i++) {
                $this->inserer($phaseId, $contexte, $tour, $i, []);
                $total++;
            }
        }

        return $total;
    }

    /**
     * @param array{p1?:?int,p2?:?int,pl1?:?int,pl2?:?int,place?:?int} $donnees
     */
    private function inserer(int $phaseId, string $contexte, ?string $tour, int $ordre, array $donnees): void
    {
        $p1 = $donnees['p1'] ?? null;
        $p2 = $donnees['p2'] ?? null;

        $st = $this->pdo->prepare(
            'INSERT INTO ' . table('rencontre')
            . ' (phase_id, contexte, tour, ordre, participation_1_id, participation_2_id,'
            . '  place_1, place_2, place_attribuee, handicap)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $st->execute([
            $phaseId,
            $contexte,
            $tour,
            $ordre,
            $p1,
            $p2,
            $donnees['pl1'] ?? null,
            $donnees['pl2'] ?? null,
            $donnees['place'] ?? null,
            ($p1 !== null && $p2 !== null) ? $this->handicap($p1, $p2) : 0,
        ]);
    }

    // -----------------------------------------------------------------
    //  Remplissage
    // -----------------------------------------------------------------

    /** Place un joueur sur un cote d'une rencontre, et fige le handicap. */
    private function placer(int $phaseId, string $contexte, string $tour, int $ordre, int $cote, int $participation): void
    {
        $colonne = $cote === 1 ? 'participation_1_id' : 'participation_2_id';

        $st = $this->pdo->prepare(
            'UPDATE ' . table('rencontre') . " SET $colonne = ?"
            . ' WHERE phase_id = ? AND contexte = ? AND tour = ? AND ordre = ?'
        );
        $st->execute([$participation, $phaseId, $contexte, $tour, $ordre]);

        $this->recalculerHandicap($phaseId, $contexte, $tour, $ordre);
        $this->propagerForfaits($phaseId);
    }

    /**
     * Installe un vainqueur de barrage a la position correspondant a la
     * place qu'il vient de gagner.
     */
    private function placerBarragiste(int $phaseId, int $participation, int $place): void
    {
        foreach (['tableau_final', 'consolation'] as $contexte) {
            foreach (['place_1' => 'participation_1_id', 'place_2' => 'participation_2_id'] as $col => $cible) {
                $st = $this->pdo->prepare(
                    'UPDATE ' . table('rencontre') . " SET $cible = ?"
                    . " WHERE phase_id = ? AND contexte = ? AND tour = '8e' AND $col = ?"
                );
                $st->execute([$participation, $phaseId, $contexte, $place]);

                if ($st->rowCount() > 0) {
                    $this->recalculerHandicapsPremierTour($phaseId, $contexte);
                    $this->propagerForfaits($phaseId);

                    return;
                }
            }
        }
    }

    /**
     * Fait passer sans jouer un joueur dont l'adversaire est absent.
     *
     * ATTENTION AU SENS D'UNE CASE VIDE
     *
     * Au PREMIER TOUR, une case vide signifie qu'aucun joueur n'occupe
     * cette position : l'effectif n'atteint pas seize. C'est un forfait,
     * l'adversaire passe.
     *
     * Aux tours SUIVANTS, une case vide signifie « pas encore
     * determine » : le match qui l'alimente n'est pas joue. Ce n'est
     * evidemment pas un forfait.
     *
     * Une case qui attend un vainqueur de barrage n'est pas vide non
     * plus : elle est reservee, et le barrage la remplira.
     */
    public function propagerForfaits(int $phaseId): void
    {
        $st = $this->pdo->prepare(
            'SELECT id, contexte, tour, ordre, participation_1_id, participation_2_id,'
            . '       place_1, place_2'
            . '  FROM ' . table('rencontre')
            . " WHERE phase_id = ? AND contexte IN ('tableau_final','consolation')"
            . "   AND tour = '8e' AND vainqueur IS NULL"
            . '   AND ((participation_1_id IS NULL) <> (participation_2_id IS NULL))'
        );
        $st->execute([$phaseId]);

        foreach ($st->fetchAll() as $r) {
            $coteVide = $r['participation_1_id'] === null ? 1 : 2;

            // Position reservee a un vainqueur de barrage : on attend.
            if ($this->attendUnBarrage($phaseId, (int) ($r['place_' . $coteVide] ?? 0))) {
                continue;
            }

            $cote    = $coteVide === 1 ? '2' : '1';
            $gagnant = (int) ($r['participation_1_id'] ?? $r['participation_2_id']);

            $maj = $this->pdo->prepare(
                'UPDATE ' . table('rencontre') . ' SET vainqueur = ?, encodee_le = NOW() WHERE id = ?'
            );
            $maj->execute([$cote, (int) $r['id']]);

            $suite = Tableau::destination('8e', (int) $r['ordre']);

            if ($suite === null) {
                continue;
            }

            $colonne = $suite['cote'] === 1 ? 'participation_1_id' : 'participation_2_id';

            $maj = $this->pdo->prepare(
                'UPDATE ' . table('rencontre') . " SET $colonne = ?"
                . ' WHERE phase_id = ? AND contexte = ? AND tour = ? AND ordre = ?'
            );
            $maj->execute([$gagnant, $phaseId, (string) $r['contexte'], $suite['tour'], $suite['match']]);

            $this->recalculerHandicap($phaseId, (string) $r['contexte'], $suite['tour'], $suite['match']);
        }
    }

    /**
     * Cette place est-elle encore attendue d'un barrage non joue ?
     */
    private function attendUnBarrage(int $phaseId, int $place): bool
    {
        if ($place === 0) {
            return false;
        }

        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . table('rencontre')
            . " WHERE phase_id = ? AND contexte = 'barrage' AND place_attribuee = ?"
        );
        $st->execute([$phaseId, $place]);

        return (int) $st->fetchColumn() > 0;
    }

    // -----------------------------------------------------------------
    //  Interne
    // -----------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function rencontre(int $rencontreId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, phase_id, contexte, tour, ordre, vainqueur,'
            . '       participation_1_id, participation_2_id, place_attribuee'
            . '  FROM ' . table('rencontre') . ' WHERE id = ?'
        );
        $st->execute([$rencontreId]);

        $ligne = $st->fetch();

        return $ligne === false ? null : $ligne;
    }

    private function recalculerHandicap(int $phaseId, string $contexte, string $tour, int $ordre): void
    {
        $st = $this->pdo->prepare(
            'SELECT id, participation_1_id, participation_2_id FROM ' . table('rencontre')
            . ' WHERE phase_id = ? AND contexte = ? AND tour = ? AND ordre = ?'
        );
        $st->execute([$phaseId, $contexte, $tour, $ordre]);

        $r = $st->fetch();

        if ($r === false || $r['participation_1_id'] === null || $r['participation_2_id'] === null) {
            return;
        }

        $maj = $this->pdo->prepare('UPDATE ' . table('rencontre') . ' SET handicap = ? WHERE id = ?');
        $maj->execute([
            $this->handicap((int) $r['participation_1_id'], (int) $r['participation_2_id']),
            (int) $r['id'],
        ]);
    }

    private function recalculerHandicapsPremierTour(int $phaseId, string $contexte): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->recalculerHandicap($phaseId, $contexte, '8e', $i);
        }
    }

    /**
     * Handicap fige entre deux participations.
     *
     * Positif : le joueur 1 est le plus fort, le joueur 2 recoit
     * l'avance.
     */
    private function handicap(int $participation1, int $participation2): int
    {
        $st = $this->pdo->prepare(
            'SELECT h.valeur FROM ' . table('participation') . ' a'
            . '  JOIN ' . table('participation') . ' b ON b.id = ?'
            . '  JOIN ' . table('handicap') . ' h'
            . '    ON h.classement_1_id = a.classement_id AND h.classement_2_id = b.classement_id'
            . ' WHERE a.id = ?'
        );
        $st->execute([$participation2, $participation1]);

        $valeur = $st->fetchColumn();

        return $valeur === false ? 0 : (int) $valeur;
    }

    /**
     * Format de jeu d'un tour de tableau.
     *
     * Retourne le format effectif : celui des rencontres du tour s'il a
     * ete fixe, celui de la phase sinon.
     */
    public function formatDuTour(int $phaseId, string $contexte, ?string $tour): FormatMatch
    {
        $sql = 'SELECT COALESCE(r.format_match, p.format_match) AS f'
             . '  FROM ' . table('rencontre') . ' r'
             . '  JOIN ' . table('phase') . ' p ON p.id = r.phase_id'
             . ' WHERE r.phase_id = ? AND r.contexte = ?'
             . ($tour === null ? ' AND r.tour IS NULL' : ' AND r.tour = ?')
             . ' LIMIT 1';

        $st = $this->pdo->prepare($sql);
        $st->execute($tour === null ? [$phaseId, $contexte] : [$phaseId, $contexte, $tour]);

        return FormatMatch::tryFrom((string) $st->fetchColumn()) ?? FormatMatch::TroisSetsSecs;
    }

    /**
     * Change le format d'un tour.
     *
     * Seules les rencontres NON ENCODEES sont touchees : un match deja
     * joue garde le format sous lequel il l'a ete, son vainqueur restant
     * son vainqueur.
     *
     * @return int nombre de rencontres modifiees
     */
    public function changerFormatTour(int $phaseId, string $contexte, ?string $tour, FormatMatch $format): int
    {
        $sql = 'UPDATE ' . table('rencontre') . ' SET format_match = ?'
             . ' WHERE phase_id = ? AND contexte = ? AND vainqueur IS NULL'
             . ($tour === null ? ' AND tour IS NULL' : ' AND tour = ?');

        $st = $this->pdo->prepare($sql);
        $st->execute(
            $tour === null
                ? [$format->value, $phaseId, $contexte]
                : [$format->value, $phaseId, $contexte, $tour]
        );

        return $st->rowCount();
    }

    /**
     * Echange deux joueurs de position.
     *
     * Reserve au premier tour et aux barrages : au-dela, les positions
     * decoulent des resultats. L'echange est refuse des qu'un resultat
     * est encode dans ce tableau, sous peine d'incoherence.
     *
     * @throws RuntimeException
     */
    public function echangerJoueurs(int $phaseId, int $rencontre1, int $cote1, int $rencontre2, int $cote2): void
    {
        $a = $this->positionEchangeable($phaseId, $rencontre1);
        $b = $this->positionEchangeable($phaseId, $rencontre2);

        if ($a['contexte'] !== $b['contexte']) {
            throw new RuntimeException('Les deux joueurs doivent appartenir au meme tableau.');
        }

        $colA = $cote1 === 1 ? 'participation_1_id' : 'participation_2_id';
        $colB = $cote2 === 1 ? 'participation_1_id' : 'participation_2_id';

        $joueurA = $a[$colA];
        $joueurB = $b[$colB];

        if ($rencontre1 === $rencontre2 && $cote1 === $cote2) {
            return;
        }

        $this->pdo->beginTransaction();

        try {
            $maj = $this->pdo->prepare('UPDATE ' . table('rencontre') . " SET $colA = ? WHERE id = ?");
            $maj->execute([$joueurB, $rencontre1]);

            $maj = $this->pdo->prepare('UPDATE ' . table('rencontre') . " SET $colB = ? WHERE id = ?");
            $maj->execute([$joueurA, $rencontre2]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        // Le handicap depend des deux joueurs presents : il est refige.
        foreach ([$rencontre1, $rencontre2] as $id) {
            $this->recalculerHandicapRencontre($id);
        }
    }

    /**
     * Verifie qu'une rencontre autorise l'echange, et la retourne.
     *
     * @return array<string,mixed>
     */
    private function positionEchangeable(int $phaseId, int $rencontreId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, phase_id, contexte, tour, vainqueur,'
            . '       participation_1_id, participation_2_id'
            . '  FROM ' . table('rencontre') . ' WHERE id = ?'
        );
        $st->execute([$rencontreId]);

        $r = $st->fetch();

        if ($r === false || (int) $r['phase_id'] !== $phaseId) {
            throw new RuntimeException('Rencontre introuvable dans cette phase.');
        }

        if ($r['contexte'] !== 'barrage' && $r['tour'] !== '8e') {
            throw new RuntimeException(
                'Seuls le barrage et le premier tour peuvent etre reamenages : '
                . 'les tours suivants decoulent des resultats.'
            );
        }

        // Ce qui compte est qu'un joueur n'ait pas deja dispute son
        // match, non que le tableau soit vierge : deplacer quelqu'un
        // dont la rencontre n'est pas jouee ne perturbe rien.
        if ($r['vainqueur'] !== null) {
            throw new RuntimeException(
                'Cette rencontre est deja jouee : ses joueurs ne peuvent plus etre deplaces.'
            );
        }

        return $r;
    }

    private function recalculerHandicapRencontre(int $rencontreId): void
    {
        $st = $this->pdo->prepare(
            'SELECT participation_1_id, participation_2_id FROM ' . table('rencontre')
            . ' WHERE id = ?'
        );
        $st->execute([$rencontreId]);

        $r = $st->fetch();

        $valeur = ($r !== false && $r['participation_1_id'] !== null && $r['participation_2_id'] !== null)
            ? $this->handicap((int) $r['participation_1_id'], (int) $r['participation_2_id'])
            : 0;

        $maj = $this->pdo->prepare('UPDATE ' . table('rencontre') . ' SET handicap = ? WHERE id = ?');
        $maj->execute([$valeur, $rencontreId]);
    }
}

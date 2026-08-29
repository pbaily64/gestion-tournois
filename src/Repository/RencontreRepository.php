<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RMCF\Tournois\Domain\ClassementPoule;
use RMCF\Tournois\Domain\FormatMatch;
use RMCF\Tournois\Domain\ResultatMatch;
use RuntimeException;
use Throwable;

/**
 * Encodage des scores et recalcul des classements de poule.
 *
 * L'ordre des matchs n'est pas contraignant : c'est un ordre conseille,
 * pas une file d'attente. En soiree, un joueur arrive en retard, une
 * poule plus fournie doit avancer plus vite, et l'organisateur lance ce
 * qu'il peut lancer. Chaque rencontre est donc independamment
 * lancable et encodable.
 */
final class RencontreRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** Marque une rencontre comme lancee, ou annule le lancement. */
    public function basculerLancement(int $rencontreId): void
    {
        $st = $this->pdo->prepare(
            'UPDATE ' . table('rencontre')
            . ' SET lancee_le = CASE WHEN lancee_le IS NULL THEN NOW() ELSE NULL END'
            . ' WHERE id = ? AND vainqueur IS NULL'
        );
        $st->execute([$rencontreId]);
    }

    /**
     * Enregistre le resultat d'une rencontre a partir des points de
     * chaque set.
     *
     * Le nombre de sets gagnes et le vainqueur en decoulent : rien n'est
     * saisi deux fois, donc rien ne peut se contredire.
     *
     * @param  list<array{0:int|string|null,1:int|string|null}> $cases
     * @throws ResultatInvalide si le score est irregulier
     */
    public function encoder(int $rencontreId, array $cases): ResultatMatch
    {
        $format   = $this->formatDeLaRencontre($rencontreId);
        $resultat = ResultatMatch::depuisCases($cases, $format);

        $this->pdo->beginTransaction();

        try {
            $st = $this->pdo->prepare(
                'UPDATE ' . table('rencontre')
                . ' SET sets_1 = ?, sets_2 = ?, vainqueur = ?, encodee_le = NOW(),'
                . '     lancee_le = COALESCE(lancee_le, NOW())'
                . ' WHERE id = ?'
            );
            $st->execute([
                $resultat->sets1,
                $resultat->sets2,
                $resultat->vainqueur(),
                $rencontreId,
            ]);

            $this->enregistrerManches($rencontreId, $resultat->manches);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->recalculerPoule($this->pouleDe($rencontreId));
        $this->invaliderClassementGeneral($rencontreId);

        return $resultat;
    }

    /**
     * Efface le resultat d'une rencontre et recalcule la poule.
     */
    public function effacer(int $rencontreId): void
    {
        $pouleId = $this->pouleDe($rencontreId);

        $this->pdo->beginTransaction();

        try {
            $st = $this->pdo->prepare(
                'DELETE FROM ' . table('manche') . ' WHERE rencontre_id = ?'
            );
            $st->execute([$rencontreId]);

            $st = $this->pdo->prepare(
                'UPDATE ' . table('rencontre')
                . ' SET sets_1 = NULL, sets_2 = NULL, vainqueur = NULL, encodee_le = NULL'
                . ' WHERE id = ?'
            );
            $st->execute([$rencontreId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $this->recalculerPoule($pouleId);
        $this->invaliderClassementGeneral($rencontreId);
    }

    /**
     * Annule le classement general fige de la phase.
     *
     * Appele des qu'un resultat de poule change. Sans cela, le
     * classement resterait fige sur des donnees perimees : c'est
     * exactement ce qui produit un ordre incoherent avec les totaux
     * affiches, sans que rien ne le signale.
     */
    private function invaliderClassementGeneral(int $rencontreId): void
    {
        $st = $this->pdo->prepare(
            'SELECT phase_id FROM ' . table('rencontre') . ' WHERE id = ?'
        );
        $st->execute([$rencontreId]);

        $phaseId = $st->fetchColumn();

        if ($phaseId === false) {
            return;
        }

        $st = $this->pdo->prepare(
            'UPDATE ' . table('participation') . ' SET place_generale = NULL'
            . ' WHERE phase_id = ? AND place_generale IS NOT NULL'
        );
        $st->execute([(int) $phaseId]);

        if ($st->rowCount() > 0) {
            $st = $this->pdo->prepare(
                'UPDATE ' . table('phase') . " SET statut = 'poules' WHERE id = ?"
            );
            $st->execute([(int) $phaseId]);
        }
    }

    /**
     * Recalcule les totaux et les places d'une poule.
     *
     * Les totaux sont derives des rencontres : ils sont stockes pour
     * l'affichage et l'impression, jamais consideres comme la verite.
     * Un recalcul complet suit chaque encodage.
     */
    public function recalculerPoule(?int $pouleId): void
    {
        if ($pouleId === null) {
            return;
        }

        // Lettre de chaque participant, pour dialoguer avec le domaine.
        $st = $this->pdo->prepare(
            'SELECT lettre, participation_id FROM ' . table('poule_participant')
            . ' WHERE poule_id = ? ORDER BY lettre'
        );
        $st->execute([$pouleId]);

        $parLettre = [];

        foreach ($st->fetchAll() as $r) {
            $parLettre[(string) $r['lettre']] = (int) $r['participation_id'];
        }

        if ($parLettre === []) {
            return;
        }

        $parParticipation = array_flip($parLettre);

        // Rencontres jouees de la poule.
        $st = $this->pdo->prepare(
            'SELECT r.participation_1_id, r.participation_2_id, r.sets_1, r.sets_2,'
            . '       (SELECT COALESCE(SUM(m.points_1), 0) FROM ' . table('manche') . ' m'
            . '         WHERE m.rencontre_id = r.id) AS points_1,'
            . '       (SELECT COALESCE(SUM(m.points_2), 0) FROM ' . table('manche') . ' m'
            . '         WHERE m.rencontre_id = r.id) AS points_2'
            . '  FROM ' . table('rencontre') . ' r'
            . ' WHERE r.poule_id = ? AND r.vainqueur IS NOT NULL'
        );
        $st->execute([$pouleId]);

        $matchs = [];

        foreach ($st->fetchAll() as $r) {
            $matchs[] = [
                'a'        => $parParticipation[(int) $r['participation_1_id']],
                'b'        => $parParticipation[(int) $r['participation_2_id']],
                'sets_a'   => (int) $r['sets_1'],
                'sets_b'   => (int) $r['sets_2'],
                'points_a' => (int) $r['points_1'],
                'points_b' => (int) $r['points_2'],
            ];
        }

        // Le departage depend du format de jeu de la phase.
        $classement = ClassementPoule::classer(
            array_keys($parLettre),
            $matchs,
            $this->formatDeLaPoule($pouleId)
        );

        // L'arbitrage de l'organisateur survit au recalcul tant que
        // l'egalite subsiste : on reordonne chaque groupe ex aequo
        // selon les places qu'il a fixees a la main.
        $classement = $this->appliquerArbitrage($pouleId, $classement, $parLettre);

        $maj = $this->pdo->prepare(
            'UPDATE ' . table('poule_participant')
            . ' SET victoires = ?, defaites = ?, sets_pour = ?, sets_contre = ?,'
            . '     place = ?, ex_aequo = ?, groupe_egalite = ?'
            . ' WHERE poule_id = ? AND participation_id = ?'
        );

        foreach ($classement as $ligne) {
            $maj->execute([
                $ligne['victoires'],
                $ligne['defaites'],
                $ligne['sets_pour'],
                $ligne['sets_contre'],
                $ligne['place'],
                $ligne['ex_aequo'] ? 1 : 0,
                $ligne['ex_aequo'] ? $ligne['groupe'] : null,
                $pouleId,
                $parLettre[$ligne['joueur']],
            ]);
        }
    }

    /**
     * Reordonne les groupes ex aequo selon l'arbitrage deja rendu.
     *
     * Un joueur dont la place a ete forcee garde sa position relative
     * au sein de son groupe. Si l'egalite disparait — un score corrige
     * separe enfin les joueurs — l'arbitrage devient sans objet et le
     * calcul reprend ses droits.
     *
     * @param  list<array<string,mixed>> $classement
     * @param  array<string,int>         $parLettre
     * @return list<array<string,mixed>>
     */
    private function appliquerArbitrage(int $pouleId, array $classement, array $parLettre): array
    {
        $st = $this->pdo->prepare(
            'SELECT participation_id, place FROM ' . table('poule_participant')
            . ' WHERE poule_id = ? AND place_forcee = 1'
        );
        $st->execute([$pouleId]);

        $forcees = [];

        foreach ($st->fetchAll() as $l) {
            $forcees[(int) $l['participation_id']] = (int) $l['place'];
        }

        if ($forcees === []) {
            return $classement;
        }

        // Regrouper, reordonner, puis reattribuer les places.
        $groupes = [];

        foreach ($classement as $ligne) {
            $groupes[$ligne['groupe']][] = $ligne;
        }

        $sortie = [];
        $place  = 1;

        foreach ($groupes as $membres) {
            if (count($membres) > 1) {
                usort($membres, static function (array $a, array $b) use ($forcees, $parLettre): int {
                    $pa = $forcees[$parLettre[$a['joueur']]] ?? $a['place'];
                    $pb = $forcees[$parLettre[$b['joueur']]] ?? $b['place'];

                    return $pa <=> $pb;
                });
            }

            foreach ($membres as $ligne) {
                $ligne['place'] = $place++;
                $sortie[]       = $ligne;
            }
        }

        return $sortie;
    }

    /**
     * Echange un joueur avec son voisin dans le classement de sa poule.
     *
     * Reserve aux joueurs qu'aucun critere ne separe : l'organisateur
     * tranche, l'application enregistre sa decision.
     *
     * @throws RuntimeException si l'echange sort du groupe a egalite
     */
    public function permuterPoule(int $pouleId, int $participationId, int $sens): void
    {
        $st = $this->pdo->prepare(
            'SELECT participation_id, place, groupe_egalite, ex_aequo'
            . '  FROM ' . table('poule_participant')
            . ' WHERE poule_id = ? ORDER BY place'
        );
        $st->execute([$pouleId]);

        $liste = $st->fetchAll();

        foreach ($liste as $i => $l) {
            if ((int) $l['participation_id'] !== $participationId) {
                continue;
            }

            $voisin = $liste[$i + $sens] ?? null;

            if ($voisin === null
                || (int) $l['ex_aequo'] !== 1
                || (int) $voisin['ex_aequo'] !== 1
                || $l['groupe_egalite'] !== $voisin['groupe_egalite']
            ) {
                throw new RuntimeException(
                    'Seuls des joueurs qu\'aucun critere ne separe peuvent etre intervertis.'
                );
            }

            $maj = $this->pdo->prepare(
                'UPDATE ' . table('poule_participant')
                . ' SET place = ?, place_forcee = 1, place_forcee_le = NOW(), place_forcee_par = ?'
                . ' WHERE poule_id = ? AND participation_id = ?'
            );

            // Trace de l'arbitrage : qui a tranche, et quand.
            $par = isset($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : null;

            $this->pdo->beginTransaction();

            try {
                $maj->execute([(int) $voisin['place'], $par, $pouleId, $participationId]);
                $maj->execute([(int) $l['place'], $par, $pouleId, (int) $voisin['participation_id']]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }

            return;
        }

        throw new RuntimeException('Joueur introuvable dans cette poule.');
    }

    /**
     * Tableau de bord d'une phase, repris de la ligne 1 des feuilles de
     * poule du classeur.
     *
     * @return array{total:int, encodes:int, en_cours:int, a_lancer:int}
     */
    public function tableauDeBord(int $phaseId): array
    {
        $sql = 'SELECT COUNT(*) AS total,'
             . '       SUM(vainqueur IS NOT NULL) AS encodes,'
             . '       SUM(vainqueur IS NULL AND lancee_le IS NOT NULL) AS en_cours,'
             . '       SUM(vainqueur IS NULL AND lancee_le IS NULL) AS a_lancer'
             . '  FROM ' . table('rencontre')
             . " WHERE phase_id = ? AND contexte = 'poule'";

        $st = $this->pdo->prepare($sql);
        $st->execute([$phaseId]);

        $r = $st->fetch();

        return [
            'total'    => (int) ($r['total'] ?? 0),
            'encodes'  => (int) ($r['encodes'] ?? 0),
            'en_cours' => (int) ($r['en_cours'] ?? 0),
            'a_lancer' => (int) ($r['a_lancer'] ?? 0),
        ];
    }

    // -----------------------------------------------------------------
    //  Interne
    // -----------------------------------------------------------------

    /** @param list<array{0:int,1:int}> $manches */
    private function enregistrerManches(int $rencontreId, array $manches): void
    {
        $st = $this->pdo->prepare('DELETE FROM ' . table('manche') . ' WHERE rencontre_id = ?');
        $st->execute([$rencontreId]);

        if ($manches === []) {
            return;
        }

        $ins = $this->pdo->prepare(
            'INSERT INTO ' . table('manche') . ' (rencontre_id, numero, points_1, points_2)'
            . ' VALUES (?, ?, ?, ?)'
        );

        $numero = 0;

        foreach ($manches as [$p1, $p2]) {
            $ins->execute([$rencontreId, ++$numero, $p1, $p2]);
        }
    }

    private function formatDeLaPoule(int $pouleId): FormatMatch
    {
        $st = $this->pdo->prepare(
            'SELECT p.format_match FROM ' . table('poule') . ' po'
            . '  JOIN ' . table('phase') . ' p ON p.id = po.phase_id'
            . ' WHERE po.id = ?'
        );
        $st->execute([$pouleId]);

        return FormatMatch::tryFrom((string) $st->fetchColumn()) ?? FormatMatch::TroisSetsSecs;
    }

    private function formatDeLaRencontre(int $rencontreId): FormatMatch
    {
        // Le format de la rencontre prime sur celui de la phase :
        // en elimination directe, chaque tour peut differer.
        $st = $this->pdo->prepare(
            'SELECT COALESCE(r.format_match, p.format_match) FROM ' . table('rencontre') . ' r'
            . '  JOIN ' . table('phase') . ' p ON p.id = r.phase_id'
            . ' WHERE r.id = ?'
        );
        $st->execute([$rencontreId]);

        $valeur = $st->fetchColumn();

        if ($valeur === false) {
            throw new RuntimeException('Rencontre introuvable.');
        }

        // Repli sur le format par defaut plutot qu'une erreur fatale :
        // une colonne vide ne doit pas bloquer l'encodage en soiree.
        return FormatMatch::tryFrom((string) $valeur) ?? FormatMatch::TroisSetsSecs;
    }

    private function pouleDe(int $rencontreId): ?int
    {
        $st = $this->pdo->prepare(
            'SELECT poule_id FROM ' . table('rencontre') . ' WHERE id = ?'
        );
        $st->execute([$rencontreId]);

        $id = $st->fetchColumn();

        return $id === false || $id === null ? null : (int) $id;
    }
}

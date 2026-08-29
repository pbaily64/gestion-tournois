<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RMCF\Tournois\Domain\OrdreMatchs;
use RMCF\Tournois\Domain\RepartitionPoules;
use RMCF\Tournois\Domain\Serpentin;
use RuntimeException;
use Throwable;

/**
 * Generation et lecture des poules d'une phase.
 *
 * La generation combine trois briques deja eprouvees :
 *   - Serpentin      : repartition des joueurs selon la regle du S
 *   - OrdreMatchs    : ordre de lancement et designation de l'arbitre
 *   - table handicap : valeur figee sur chaque rencontre
 *
 * CONVENTION DE SIGNE DU HANDICAP
 * La table `handicap` donne l'avantage de classement du joueur 1 :
 *   - valeur > 0 : le joueur 1 est le plus fort, le joueur 2 recoit
 *                  `valeur` points d'avance
 *   - valeur < 0 : l'inverse, le joueur 1 recoit |valeur| points
 *   - valeur = 0 : classements identiques, pas d'avance
 */
final class PouleRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Genere les poules, leurs participants et toutes les rencontres.
     *
     * @return array{nb_poules:int, nb_matchs:int, tailles:list<int>}
     * @throws RuntimeException si la generation n'est pas possible
     */
    public function generer(int $phaseId, int $nbPoules): array
    {
        if ($this->existent($phaseId)) {
            throw new RuntimeException(
                'Les poules de cette phase existent deja. Supprimez-les avant d\'en generer de nouvelles.'
            );
        }

        $participants = $this->participantsOrdonnes($phaseId);
        $effectif     = count($participants);

        $tailles = RepartitionPoules::tailles($effectif, $nbPoules);

        if ($tailles === null) {
            throw new RuntimeException(sprintf(
                'Repartition impossible : %d joueur(s) en %d poule(s). '
                . 'Chaque poule doit compter de %d a %d joueurs.',
                $effectif,
                $nbPoules,
                Serpentin::JOUEURS_PAR_POULE_MIN,
                Serpentin::JOUEURS_PAR_POULE_MAX
            ));
        }

        // Le serpentin travaille sur la liste triee : classement
        // decroissant, puis nom et prenom. C'est l'ordre renvoye par
        // participantsOrdonnes().
        $repartition = Serpentin::repartir($participants, $nbPoules);

        $this->pdo->beginTransaction();

        try {
            $nbMatchs = 0;

            foreach ($repartition as $indice => $membres) {
                $pouleId = $this->creerPoule($phaseId, $indice);
                $lettres = $this->placerParticipants($pouleId, $membres);
                $nbMatchs += $this->creerRencontres($phaseId, $pouleId, $lettres);
            }

            $this->marquerPhase($phaseId, $nbPoules);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'nb_poules' => $nbPoules,
            'nb_matchs' => $nbMatchs,
            'tailles'   => array_map('count', $repartition),
        ];
    }

    /**
     * Supprime toutes les poules d'une phase.
     *
     * Les rencontres et les affectations suivent par cascade. A n'appeler
     * que si aucun score n'est encode : le controle est fait ici.
     */
    public function supprimer(int $phaseId): void
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . table('rencontre')
            . " WHERE phase_id = ? AND contexte = 'poule' AND vainqueur IS NOT NULL"
        );
        $st->execute([$phaseId]);

        if ((int) $st->fetchColumn() > 0) {
            throw new RuntimeException(
                'Des scores sont deja encodes dans cette phase : les poules ne peuvent plus etre supprimees.'
            );
        }

        $this->pdo->beginTransaction();

        try {
            $del = $this->pdo->prepare(
                'DELETE FROM ' . table('rencontre') . " WHERE phase_id = ? AND contexte = 'poule'"
            );
            $del->execute([$phaseId]);

            $del = $this->pdo->prepare('DELETE FROM ' . table('poule') . ' WHERE phase_id = ?');
            $del->execute([$phaseId]);

            $upd = $this->pdo->prepare(
                'UPDATE ' . table('phase') . " SET statut = 'preparation', nb_poules = NULL WHERE id = ?"
            );
            $upd->execute([$phaseId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function existent(int $phaseId): bool
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM ' . table('poule') . ' WHERE phase_id = ?');
        $st->execute([$phaseId]);

        return (int) $st->fetchColumn() > 0;
    }

    /**
     * Poules d'une phase, avec leurs participants dans l'ordre des
     * lettres.
     *
     * @return list<array{id:int,lettre:string,membres:list<array<string,mixed>>}>
     */
    public function poules(int $phaseId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, lettre FROM ' . table('poule') . ' WHERE phase_id = ? ORDER BY indice'
        );
        $st->execute([$phaseId]);
        $poules = $st->fetchAll();

        $sql = 'SELECT pp.lettre, pp.participation_id, j.nom, j.prenom, c.code AS classement'
             . '  FROM ' . table('poule_participant') . ' pp'
             . '  JOIN ' . table('participation') . ' pa ON pa.id = pp.participation_id'
             . '  JOIN ' . table('joueur') . ' j ON j.id = pa.joueur_id'
             . '  JOIN ' . table('classement') . ' c ON c.id = pa.classement_id'
             . ' WHERE pp.poule_id = ? ORDER BY pp.lettre';
        $membres = $this->pdo->prepare($sql);

        $out = [];

        foreach ($poules as $p) {
            $membres->execute([$p['id']]);

            $out[] = [
                'id'      => (int) $p['id'],
                'lettre'  => (string) $p['lettre'],
                'membres' => $membres->fetchAll(),
            ];
        }

        return $out;
    }

    /**
     * Rencontres d'une poule, dans l'ordre de lancement.
     *
     * @return list<array<string,mixed>>
     */
    public function rencontres(int $pouleId): array
    {
        $sql = 'SELECT r.id, r.ordre, r.handicap, r.sets_1, r.sets_2, r.vainqueur,'
             . '       r.lancee_le, r.encodee_le,'
             . '       pp1.lettre AS lettre_1, j1.nom AS nom_1, j1.prenom AS prenom_1, c1.code AS classement_1,'
             . '       pp2.lettre AS lettre_2, j2.nom AS nom_2, j2.prenom AS prenom_2, c2.code AS classement_2,'
             . '       ppa.lettre AS lettre_arbitre, ja.nom AS nom_arbitre, ja.prenom AS prenom_arbitre'
             . '  FROM ' . table('rencontre') . ' r'
             . '  JOIN ' . table('participation') . ' p1 ON p1.id = r.participation_1_id'
             . '  JOIN ' . table('joueur') . ' j1 ON j1.id = p1.joueur_id'
             . '  JOIN ' . table('classement') . ' c1 ON c1.id = p1.classement_id'
             . '  JOIN ' . table('poule_participant') . ' pp1'
             . '    ON pp1.poule_id = r.poule_id AND pp1.participation_id = r.participation_1_id'
             . '  JOIN ' . table('participation') . ' p2 ON p2.id = r.participation_2_id'
             . '  JOIN ' . table('joueur') . ' j2 ON j2.id = p2.joueur_id'
             . '  JOIN ' . table('classement') . ' c2 ON c2.id = p2.classement_id'
             . '  JOIN ' . table('poule_participant') . ' pp2'
             . '    ON pp2.poule_id = r.poule_id AND pp2.participation_id = r.participation_2_id'
             . '  LEFT JOIN ' . table('participation') . ' pa ON pa.id = r.arbitre_participation_id'
             . '  LEFT JOIN ' . table('joueur') . ' ja ON ja.id = pa.joueur_id'
             . '  LEFT JOIN ' . table('poule_participant') . ' ppa'
             . '    ON ppa.poule_id = r.poule_id AND ppa.participation_id = r.arbitre_participation_id'
             . ' WHERE r.poule_id = ? ORDER BY r.ordre';

        $st = $this->pdo->prepare($sql);
        $st->execute([$pouleId]);

        return $st->fetchAll();
    }

    // -----------------------------------------------------------------
    //  Interne
    // -----------------------------------------------------------------

    /**
     * Participants pointes, tries comme le serpentin les attend :
     * classement decroissant, puis nom et prenom.
     *
     * @return list<array{id:int,classement_id:int,rang:int}>
     */
    private function participantsOrdonnes(int $phaseId): array
    {
        $sql = 'SELECT pa.id, pa.classement_id, c.rang'
             . '  FROM ' . table('participation') . ' pa'
             . '  JOIN ' . table('joueur') . ' j ON j.id = pa.joueur_id'
             . '  JOIN ' . table('classement') . ' c ON c.id = pa.classement_id'
             . ' WHERE pa.phase_id = ?'
             . ' ORDER BY c.rang DESC, j.nom, j.prenom';

        $st = $this->pdo->prepare($sql);
        $st->execute([$phaseId]);

        return array_map(
            static fn (array $r): array => [
                'id'            => (int) $r['id'],
                'classement_id' => (int) $r['classement_id'],
                'rang'          => (int) $r['rang'],
            ],
            $st->fetchAll()
        );
    }

    private function creerPoule(int $phaseId, int $indice): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO ' . table('poule') . ' (phase_id, indice, lettre) VALUES (?, ?, ?)'
        );
        $st->execute([$phaseId, $indice, Serpentin::libelle($indice)]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Place les membres et retourne la correspondance lettre =>
     * participation, indispensable pour creer les rencontres.
     *
     * @param  list<array{id:int,classement_id:int,rang:int}> $membres
     * @return array<string, array{id:int,classement_id:int}>
     */
    private function placerParticipants(int $pouleId, array $membres): array
    {
        $st = $this->pdo->prepare(
            'INSERT INTO ' . table('poule_participant') . ' (poule_id, participation_id, lettre)'
            . ' VALUES (?, ?, ?)'
        );

        $lettres = [];

        foreach ($membres as $i => $m) {
            $lettre = Serpentin::libelle($i);
            $st->execute([$pouleId, $m['id'], $lettre]);
            $lettres[$lettre] = $m;
        }

        return $lettres;
    }

    /**
     * Cree les rencontres d'une poule selon la sequence federale, avec
     * le handicap fige et l'arbitre designe.
     *
     * @param array<string, array{id:int,classement_id:int}> $lettres
     */
    private function creerRencontres(int $phaseId, int $pouleId, array $lettres): int
    {
        $sequence = OrdreMatchs::pour(count($lettres));

        $sql = 'INSERT INTO ' . table('rencontre')
             . ' (phase_id, contexte, poule_id, ordre, participation_1_id,'
             . '  participation_2_id, arbitre_participation_id, handicap)'
             . " VALUES (?, 'poule', ?, ?, ?, ?, ?, ?)";
        $st = $this->pdo->prepare($sql);

        foreach ($sequence as $i => [$a, $b, $arbitre]) {
            $st->execute([
                $phaseId,
                $pouleId,
                $i + 1,
                $lettres[$a]['id'],
                $lettres[$b]['id'],
                $lettres[$arbitre]['id'] ?? null,
                $this->handicap($lettres[$a]['classement_id'], $lettres[$b]['classement_id']),
            ]);
        }

        return count($sequence);
    }

    /**
     * Valeur figee du handicap pour un couple de classements.
     *
     * Elle est copiee sur la rencontre et n'evolue plus : c'est le
     * chiffre annonce aux joueurs sur la feuille de match, il ne doit
     * pas changer si la table de reference est corrigee plus tard.
     */
    private function handicap(int $classement1, int $classement2): int
    {
        static $cache = [];

        $cle = $classement1 . ':' . $classement2;

        if (isset($cache[$cle])) {
            return $cache[$cle];
        }

        $st = $this->pdo->prepare(
            'SELECT valeur FROM ' . table('handicap')
            . ' WHERE classement_1_id = ? AND classement_2_id = ?'
        );
        $st->execute([$classement1, $classement2]);

        $valeur = $st->fetchColumn();

        if ($valeur === false) {
            throw new RuntimeException(
                "Handicap absent pour le couple de classements $classement1 / $classement2."
            );
        }

        return $cache[$cle] = (int) $valeur;
    }

    private function marquerPhase(int $phaseId, int $nbPoules): void
    {
        $st = $this->pdo->prepare(
            'UPDATE ' . table('phase') . " SET nb_poules = ?, statut = 'poules' WHERE id = ?"
        );
        $st->execute([$nbPoules, $phaseId]);
    }
}

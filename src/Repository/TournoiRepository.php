<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Creation, consultation et suppression des tournois.
 *
 * Un tournoi comporte une a plusieurs phases. Le type — poules ou
 * elimination directe — est fixe a la creation et vaut pour toutes ses
 * phases. Le bareme de points lui est rattache.
 */
final class TournoiRepository
{
    /** Bareme par defaut, repris de la feuille ATTRIBUTION DES POINTS. */
    public const BAREME_DEFAUT = [
        ['participation', null, 'presence', 5, 'Participation'],
        ['poule', null, 'victoire', 1, 'Par victoire en poule'],
        ['poule', null, 'bonus_1er', 5, 'Bonus premier de poule'],
        ['parcours', 'consolation', 'non_qualifie', 2, 'Non qualifié'],
        ['parcours', 'consolation', 'barragiste', 3, 'Barragiste'],
        ['parcours', 'consolation', '8e', 5, 'Consolante : 1/8'],
        ['parcours', 'consolation', 'quart', 10, 'Consolante : 1/4'],
        ['parcours', 'consolation', 'demie', 15, 'Consolante : 1/2'],
        ['parcours', 'consolation', 'finaliste', 20, 'Consolante : finaliste'],
        ['parcours', 'consolation', 'vainqueur', 25, 'Consolante : vainqueur'],
        ['parcours', 'tableau_final', '8e', 35, 'Tableau final : 1/8'],
        ['parcours', 'tableau_final', 'quart', 45, 'Tableau final : 1/4'],
        ['parcours', 'tableau_final', 'demie', 65, 'Tableau final : 1/2'],
        ['parcours', 'tableau_final', 'finaliste', 100, 'Tableau final : finaliste'],
        ['parcours', 'tableau_final', 'vainqueur', 120, 'Tableau final : vainqueur'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Tous les tournois, les plus recents en tete.
     *
     * @return list<array{id:int,libelle:string,saison:?string,type:string,statut:string,nb_phases:int}>
     */
    public function tous(): array
    {
        $sql = 'SELECT t.id, t.libelle, t.saison, t.type, t.statut,'
             . '       (SELECT COUNT(*) FROM ' . table('phase') . ' p'
             . '         WHERE p.tournoi_id = t.id) AS nb_phases'
             . '  FROM ' . table('tournoi') . ' t'
             . ' ORDER BY t.cree_le DESC, t.id DESC';

        /** @var list<array{id:int,libelle:string,saison:?string,type:string,statut:string,nb_phases:int}> $lignes */
        $lignes = $this->pdo->query($sql)->fetchAll();

        return $lignes;
    }

    /**
     * Cree un tournoi, ses phases et son bareme.
     *
     * @param  list<array{libelle:string,date:?string}> $phases
     * @param  array<string,int>                        $bareme cle => points
     * @throws RuntimeException
     */
    public function creer(
        string $libelle,
        ?string $saison,
        string $type,
        array $phases,
        array $bareme = []
    ): int {
        $libelle = trim($libelle);

        if ($libelle === '') {
            throw new RuntimeException('Le nom du tournoi est obligatoire.');
        }

        if ($phases === []) {
            throw new RuntimeException('Il faut au moins une phase.');
        }

        if (!in_array($type, ['poules', 'elimination_directe'], true)) {
            throw new RuntimeException('Type de tournoi inconnu.');
        }

        $this->pdo->beginTransaction();

        try {
            $st = $this->pdo->prepare(
                'INSERT INTO ' . table('tournoi') . ' (libelle, saison, type, statut)'
                . " VALUES (?, ?, ?, 'en_cours')"
            );
            $st->execute([$libelle, $saison !== '' ? $saison : null, $type]);

            $tournoiId = (int) $this->pdo->lastInsertId();

            $ins = $this->pdo->prepare(
                'INSERT INTO ' . table('phase') . ' (tournoi_id, numero, libelle, date_phase)'
                . ' VALUES (?, ?, ?, ?)'
            );

            foreach (array_values($phases) as $i => $p) {
                $nom = trim($p['libelle'] ?? '');

                $ins->execute([
                    $tournoiId,
                    $i + 1,
                    $nom !== '' ? $nom : 'Phase ' . ($i + 1),
                    ($p['date'] ?? '') !== '' ? $p['date'] : null,
                ]);
            }

            $this->ecrireBareme($tournoiId, $bareme);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $tournoiId;
    }

    /**
     * Modifie le nom, la saison et l'etat d'un tournoi.
     *
     * Le type — poules ou elimination directe — n'est pas modifiable :
     * il conditionne la structure des phases deja creees.
     */
    public function modifier(int $id, string $libelle, ?string $saison, string $statut): void
    {
        $libelle = trim($libelle);

        if ($libelle === '') {
            throw new RuntimeException('Le nom du tournoi est obligatoire.');
        }

        if (!in_array($statut, ['preparation', 'en_cours', 'termine', 'archive'], true)) {
            throw new RuntimeException('Etat de tournoi inconnu.');
        }

        $st = $this->pdo->prepare(
            'UPDATE ' . table('tournoi') . ' SET libelle = ?, saison = ?, statut = ? WHERE id = ?'
        );
        $st->execute([$libelle, ($saison ?? '') !== '' ? $saison : null, $statut, $id]);
    }

    /**
     * Ajoute une phase a la suite des existantes.
     *
     * Le numero suit la derniere phase ; le libelle vaut « Phase n ».
     */
    public function ajouterPhase(int $tournoiId, ?string $date): int
    {
        $st = $this->pdo->prepare(
            'SELECT COALESCE(MAX(numero), 0) FROM ' . table('phase') . ' WHERE tournoi_id = ?'
        );
        $st->execute([$tournoiId]);

        $numero = (int) $st->fetchColumn() + 1;

        $ins = $this->pdo->prepare(
            'INSERT INTO ' . table('phase') . ' (tournoi_id, numero, libelle, date_phase)'
            . ' VALUES (?, ?, ?, ?)'
        );
        $ins->execute([$tournoiId, $numero, 'Phase ' . $numero, ($date ?? '') !== '' ? $date : null]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Supprime un tournoi et tout ce qui en depend.
     *
     * La suppression est conduite explicitement, dans l'ordre inverse
     * des dependances : les rencontres referencent les participations
     * sans cascade, une suppression en chaine se heurterait a cette
     * contrainte.
     *
     * LES JOUEURS NE SONT JAMAIS SUPPRIMES : ils survivent aux tournois
     * et seront pointes dans les suivants.
     */
    public function supprimer(int $tournoiId): void
    {
        $this->pdo->beginTransaction();

        try {
            $phases = $this->pdo->prepare(
                'SELECT id FROM ' . table('phase') . ' WHERE tournoi_id = ?'
            );
            $phases->execute([$tournoiId]);

            $ids = array_map('intval', $phases->fetchAll(PDO::FETCH_COLUMN));

            foreach ($ids as $phaseId) {
                $this->viderPhase($phaseId);
            }

            foreach ([
                'DELETE FROM ' . table('bareme') . ' WHERE tournoi_id = ?',
                'DELETE FROM ' . table('phase') . ' WHERE tournoi_id = ?',
                'DELETE FROM ' . table('tournoi') . ' WHERE id = ?',
            ] as $sql) {
                $st = $this->pdo->prepare($sql);
                $st->execute([$tournoiId]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Vide une phase de ses poules, rencontres et participations, sans
     * supprimer la phase elle-meme.
     */
    public function viderPhase(int $phaseId): void
    {
        $requetes = [
            'DELETE m FROM ' . table('manche') . ' m'
                . '  JOIN ' . table('rencontre') . ' r ON r.id = m.rencontre_id'
                . ' WHERE r.phase_id = ?',
            'DELETE FROM ' . table('rencontre') . ' WHERE phase_id = ?',
            'DELETE pp FROM ' . table('poule_participant') . ' pp'
                . '  JOIN ' . table('poule') . ' po ON po.id = pp.poule_id'
                . ' WHERE po.phase_id = ?',
            'DELETE FROM ' . table('poule') . ' WHERE phase_id = ?',
            'DELETE FROM ' . table('participation') . ' WHERE phase_id = ?',
        ];

        foreach ($requetes as $sql) {
            $st = $this->pdo->prepare($sql);
            $st->execute([$phaseId]);
        }
    }

    /** Supprime une phase et tout son contenu. */
    public function supprimerPhase(int $phaseId): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->viderPhase($phaseId);

            $st = $this->pdo->prepare('DELETE FROM ' . table('phase') . ' WHERE id = ?');
            $st->execute([$phaseId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Modifie la date et le libelle d'une phase. */
    public function modifierPhase(int $phaseId, string $libelle, ?string $date): void
    {
        $libelle = trim($libelle);

        $st = $this->pdo->prepare(
            'UPDATE ' . table('phase') . ' SET libelle = ?, date_phase = ? WHERE id = ?'
        );
        $st->execute([
            $libelle !== '' ? $libelle : 'Phase',
            ($date ?? '') !== '' ? $date : null,
            $phaseId,
        ]);
    }

    /**
     * Bareme d'un tournoi.
     *
     * @return list<array{id:int,type:string,contexte:?string,cle:string,points:int}>
     */
    public function bareme(int $tournoiId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, type, contexte, cle, points FROM ' . table('bareme')
            . ' WHERE tournoi_id = ? ORDER BY id'
        );
        $st->execute([$tournoiId]);

        /** @var list<array{id:int,type:string,contexte:?string,cle:string,points:int}> $lignes */
        $lignes = $st->fetchAll();

        return $lignes;
    }

    /** @param array<string,int> $valeurs cle => points */
    private function ecrireBareme(int $tournoiId, array $valeurs): void
    {
        $ins = $this->pdo->prepare(
            'INSERT INTO ' . table('bareme') . ' (tournoi_id, type, contexte, cle, points)'
            . ' VALUES (?, ?, ?, ?, ?)'
        );

        foreach (self::BAREME_DEFAUT as [$type, $contexte, $cle, $defaut]) {
            $ins->execute([
                $tournoiId,
                $type,
                $contexte,
                $cle,
                $valeurs[$cle] ?? $defaut,
            ]);
        }
    }
}

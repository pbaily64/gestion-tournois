<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RMCF\Tournois\Domain\FormatMatch;
use RuntimeException;

/**
 * Acces aux tournois et a leurs phases.
 *
 * Une phase est une soiree : l'equivalent d'un classeur MbNx.xlsm.
 * Une phase encore en statut « preparation » est pointable, ce qui
 * permet d'inscrire des joueurs pour des soirees a venir.
 */
final class PhaseRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array{id:int,libelle:string,saison:?string,statut:string}>
     */
    public function tournois(): array
    {
        $sql = 'SELECT id, libelle, saison, type, statut FROM ' . table('tournoi')
             . ' ORDER BY cree_le DESC, id DESC';

        /** @var list<array{id:int,libelle:string,saison:?string,statut:string}> $lignes */
        $lignes = $this->pdo->query($sql)->fetchAll();

        return $lignes;
    }

    /** @return array{id:int,libelle:string,saison:?string,statut:string}|null */
    public function tournoi(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, libelle, saison, type, statut FROM ' . table('tournoi') . ' WHERE id = ?'
        );
        $st->execute([$id]);

        /** @var array{id:int,libelle:string,saison:?string,statut:string}|false $ligne */
        $ligne = $st->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * Phases d'un tournoi, avec le nombre de joueurs pointes et l'etat
     * d'avancement des matchs de poule.
     *
     * @return list<array{id:int,numero:int,date_phase:?string,statut:string,nb_pointes:int,nb_matchs:int,nb_encodes:int}>
     */
    public function phases(int $tournoiId): array
    {
        $sql = 'SELECT p.id, p.numero, p.libelle, p.date_phase, p.statut,'
             . '       (SELECT COUNT(*) FROM ' . table('participation') . ' pa'
             . '         WHERE pa.phase_id = p.id) AS nb_pointes,'
             . '       (SELECT COUNT(*) FROM ' . table('rencontre') . ' r'
             . "         WHERE r.phase_id = p.id AND r.contexte = 'poule') AS nb_matchs,"
             . '       (SELECT COUNT(*) FROM ' . table('rencontre') . ' r'
             . "         WHERE r.phase_id = p.id AND r.contexte = 'poule'"
             . '           AND r.vainqueur IS NOT NULL) AS nb_encodes'
             . '  FROM ' . table('phase') . ' p'
             . ' WHERE p.tournoi_id = ?'
             . ' ORDER BY p.date_phase IS NULL, p.date_phase, p.numero';

        $st = $this->pdo->prepare($sql);
        $st->execute([$tournoiId]);

        /** @var list<array{id:int,numero:int,date_phase:?string,statut:string,nb_pointes:int,nb_matchs:int,nb_encodes:int}> $lignes */
        $lignes = $st->fetchAll();

        return $lignes;
    }

    /** @return array{id:int,tournoi_id:int,numero:int,date_phase:?string,statut:string,format_match:string,cases_feuille:int,handicap_actif:int,nb_poules:?int,avec_consolation:int}|null */
    public function phase(int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, tournoi_id, numero, libelle, date_phase, statut,'
            . '       format_match, cases_feuille, handicap_actif,'
            . '       nb_poules, avec_consolation'
            . '  FROM ' . table('phase') . ' WHERE id = ?'
        );
        $st->execute([$id]);

        /** @var array<string,mixed>|false $ligne */
        $ligne = $st->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * Le pointage est-il encore modifiable ?
     *
     * Des que les poules sont generees, retirer un joueur laisserait une
     * poule incoherente : le pointage se verrouille.
     */
    public function pointageOuvert(int $phaseId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . table('poule') . ' WHERE phase_id = ?'
        );
        $st->execute([$phaseId]);

        return (int) $st->fetchColumn() === 0;
    }

    public function creerPhase(int $tournoiId, int $numero, ?string $date): int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO ' . table('phase') . ' (tournoi_id, numero, date_phase) VALUES (?, ?, ?)'
        );
        $st->execute([$tournoiId, $numero, $date !== '' ? $date : null]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Le format de jeu est-il encore modifiable ?
     *
     * Il se decide avant de lancer les matchs. Des qu'un seul resultat
     * est encode, il est fige : changer les regles en cours de route
     * rendrait la phase incoherente, une partie des matchs ayant ete
     * joues sous un format et le reste sous un autre.
     */
    public function formatModifiable(int $phaseId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . table('rencontre')
            . ' WHERE phase_id = ? AND vainqueur IS NOT NULL'
        );
        $st->execute([$phaseId]);

        return (int) $st->fetchColumn() === 0;
    }

    /**
     * Change le format de jeu d'une phase.
     *
     * Le changement est rare mais possible. Il entraine :
     *   - l'effacement des resultats devenus impossibles sous le
     *     nouveau format — un 2-1 n'existe pas en trois sets gagnants ;
     *   - le recalcul de tous les classements de poule, la regle de
     *     departage dependant du format ;
     *   - l'annulation du classement general, s'il etait fige.
     *
     * @return string compte rendu destine a l'organisateur
     */
    public function changerFormat(int $phaseId, FormatMatch $format, ?object $repoRencontre = null): string
    {
        $st = $this->pdo->prepare(
            'SELECT id, sets_1, sets_2 FROM ' . table('rencontre')
            . ' WHERE phase_id = ? AND vainqueur IS NOT NULL'
        );
        $st->execute([$phaseId]);

        $aEffacer = [];

        foreach ($st->fetchAll() as $r) {
            if ($format->verifier((int) $r['sets_1'], (int) $r['sets_2']) !== null) {
                $aEffacer[] = (int) $r['id'];
            }
        }

        $maj = $this->pdo->prepare(
            'UPDATE ' . table('phase') . ' SET format_match = ?, cases_feuille = ? WHERE id = ?'
        );
        $maj->execute([$format->value, $format->nombreDeCases(), $phaseId]);

        if ($repoRencontre === null) {
            return 'Format de jeu : ' . $format->libelle() . '.';
        }

        foreach ($aEffacer as $rencontreId) {
            $repoRencontre->effacer($rencontreId);
        }

        // Recalcul de toutes les poules : la regle de departage a change.
        $st = $this->pdo->prepare(
            'SELECT id FROM ' . table('poule') . ' WHERE phase_id = ?'
        );
        $st->execute([$phaseId]);

        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pouleId) {
            $repoRencontre->recalculerPoule((int) $pouleId);
        }

        $message = 'Format de jeu : ' . $format->libelle() . '. Classements recalcules.';

        if ($aEffacer !== []) {
            $message .= sprintf(
                ' %d resultat(s) devenu(s) impossible(s) ont ete effaces : ils sont a reencoder.',
                count($aEffacer)
            );
        }

        return $message;
    }
}

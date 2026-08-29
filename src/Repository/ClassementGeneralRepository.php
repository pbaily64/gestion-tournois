<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RMCF\Tournois\Domain\ClassementGeneral;
use RMCF\Tournois\Domain\FormatMatch;
use RuntimeException;
use Throwable;

/**
 * Classement general d'une phase, apres les poules.
 *
 * Le classement est calcule a la demande tant qu'il n'est pas valide.
 * Une fois valide, il est fige en base : c'est lui qui determinera la
 * composition des barrages et des tableaux, il ne doit plus bouger sous
 * l'effet d'une correction de score sans que l'organisateur le sache.
 */
final class ClassementGeneralRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Calcule le classement a partir des resultats de poule.
     *
     * @return list<array<string,mixed>>
     */
    public function calculer(int $phaseId, FormatMatch $format): array
    {
        $lignes = $this->lignes($phaseId);

        $classement = ClassementGeneral::classer(
            array_map(
                static fn (array $l): array => [
                    'id'          => (int) $l['participation_id'],
                    'place_poule' => $l['place_poule'] !== null ? (int) $l['place_poule'] : null,
                    'victoires'   => (int) $l['victoires'],
                    'sets_pour'   => (int) $l['sets_pour'],
                    'sets_contre' => (int) $l['sets_contre'],
                ],
                $lignes
            ),
            $format
        );

        return $this->rattacher($classement, $lignes);
    }

    /**
     * Classement fige, tel qu'il a ete valide.
     *
     * La forme retournee est la meme que celle de calculer() : les
     * pages n'ont pas a savoir si le classement est calcule ou fige.
     *
     * @return list<array<string,mixed>>
     */
    public function fige(int $phaseId): array
    {
        $lignes = $this->lignes($phaseId, true);

        return array_map(
            static fn (array $l): array => $l + [
                'id'       => (int) $l['participation_id'],
                'place'    => (int) $l['place_generale'],
                'diff'     => (int) $l['sets_pour'] - (int) $l['sets_contre'],
                'ex_aequo' => false,
            ],
            $lignes
        );
    }

    /**
     * Le classement est-il valide ?
     *
     * La validation est marquee par le statut de la phase, non par la
     * presence de places : celles-ci existent des le premier
     * reordonnancement manuel, avant validation.
     */
    public function estValide(int $phaseId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT statut FROM ' . table('phase') . ' WHERE id = ?'
        );
        $st->execute([$phaseId]);

        return in_array((string) $st->fetchColumn(), ['barrage', 'tableaux', 'terminee'], true);
    }

    /** Un ordre manuel a-t-il deja ete fige ? */
    public function ordreFige(int $phaseId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . table('participation')
            . ' WHERE phase_id = ? AND place_generale IS NOT NULL'
        );
        $st->execute([$phaseId]);

        return (int) $st->fetchColumn() > 0;
    }

    /**
     * Ecrit en base l'ordre calcule, pour pouvoir ensuite le retoucher.
     *
     * Les fleches de reordonnancement agissent sur des places
     * enregistrees : il faut donc materialiser le calcul avant la
     * premiere permutation.
     */
    public function materialiser(int $phaseId, FormatMatch $format): void
    {
        if ($this->ordreFige($phaseId)) {
            return;
        }

        $maj = $this->pdo->prepare(
            'UPDATE ' . table('participation') . ' SET place_generale = ? WHERE id = ?'
        );

        foreach ($this->calculer($phaseId, $format) as $ligne) {
            $maj->execute([$ligne['place'], $ligne['id']]);
        }
    }

    /**
     * Classement a afficher : l'ordre manuel s'il existe, le calcul
     * sinon.
     *
     * @return list<array<string,mixed>>
     */
    public function ordonner(int $phaseId, FormatMatch $format): array
    {
        return $this->ordreFige($phaseId)
            ? $this->fige($phaseId)
            : $this->calculer($phaseId, $format);
    }

    /**
     * Fige le classement calcule.
     *
     * @throws RuntimeException si des matchs restent a jouer
     */
    public function valider(int $phaseId, FormatMatch $format): int
    {
        $restants = $this->matchsRestants($phaseId);

        if ($restants > 0) {
            throw new RuntimeException(sprintf(
                '%d match(s) de poule ne sont pas encore encodes.',
                $restants
            ));
        }

        $this->pdo->beginTransaction();

        try {
            $this->materialiser($phaseId, $format);

            $st = $this->pdo->prepare(
                'UPDATE ' . table('participation')
                . ' SET poursuit = COALESCE(poursuit, 1) WHERE phase_id = ?'
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

        return count($this->fige($phaseId));
    }

    /** Annule la validation et rend le classement de nouveau calcule. */
    public function annuler(int $phaseId): void
    {
        $this->pdo->beginTransaction();

        try {
            $st = $this->pdo->prepare(
                'UPDATE ' . table('participation')
                . ' SET place_generale = NULL WHERE phase_id = ?'
            );
            $st->execute([$phaseId]);

            $st = $this->pdo->prepare(
                'UPDATE ' . table('phase') . " SET statut = 'poules' WHERE id = ?"
            );
            $st->execute([$phaseId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Echange deux participants adjacents du classement fige.
     *
     * Sert a trancher une egalite que la regle ne resout pas : la
     * decision revient a l'organisateur, et l'application se contente
     * d'appliquer son arbitrage.
     */
    public function permuter(int $phaseId, int $participationId, int $sens, FormatMatch $format): void
    {
        // Les fleches agissent sur des places enregistrees.
        $this->materialiser($phaseId, $format);

        $st = $this->pdo->prepare(
            'SELECT id, place_generale FROM ' . table('participation')
            . ' WHERE phase_id = ? AND place_generale IS NOT NULL'
            . ' ORDER BY place_generale'
        );
        $st->execute([$phaseId]);

        $liste = $st->fetchAll();

        foreach ($liste as $i => $l) {
            if ((int) $l['id'] !== $participationId) {
                continue;
            }

            $voisin = $liste[$i + $sens] ?? null;

            if ($voisin === null) {
                return;
            }

            $maj = $this->pdo->prepare(
                'UPDATE ' . table('participation') . ' SET place_generale = ? WHERE id = ?'
            );

            $this->pdo->beginTransaction();

            try {
                $maj->execute([(int) $voisin['place_generale'], (int) $l['id']]);
                $maj->execute([(int) $l['place_generale'], (int) $voisin['id']]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }

            return;
        }
    }

    /**
     * Enregistre qui poursuit la phase.
     *
     * A l'issue du classement, chaque joueur indique s'il continue.
     * Ceux qui s'arretent ne figurent ni dans les barrages ni dans les
     * tableaux, mais conservent leurs points de poule.
     *
     * @param list<int> $poursuivent identifiants de participation
     */
    public function enregistrerPoursuite(int $phaseId, array $poursuivent): void
    {
        $this->pdo->beginTransaction();

        try {
            $st = $this->pdo->prepare(
                'UPDATE ' . table('participation') . ' SET poursuit = 0 WHERE phase_id = ?'
            );
            $st->execute([$phaseId]);

            if ($poursuivent !== []) {
                $marques = implode(',', array_fill(0, count($poursuivent), '?'));
                $st = $this->pdo->prepare(
                    'UPDATE ' . table('participation') . ' SET poursuit = 1'
                    . " WHERE phase_id = ? AND id IN ($marques)"
                );
                $st->execute(array_merge([$phaseId], array_map('intval', $poursuivent)));
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Nombre de matchs de poule encore a encoder. */
    public function matchsRestants(int $phaseId): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . table('rencontre')
            . " WHERE phase_id = ? AND contexte = 'poule' AND vainqueur IS NULL"
        );
        $st->execute([$phaseId]);

        return (int) $st->fetchColumn();
    }

    // -----------------------------------------------------------------
    //  Interne
    // -----------------------------------------------------------------

    /**
     * Donnees brutes d'une phase : un participant, sa poule, ses totaux.
     *
     * @return list<array<string,mixed>>
     */
    private function lignes(int $phaseId, bool $figees = false): array
    {
        $sql = 'SELECT pa.id AS participation_id, pa.poursuit, pa.place_generale,'
             . '       j.nom, j.prenom, c.code AS classement,'
             . '       po.lettre AS poule, pp.lettre AS lettre_poule,'
             . '       pp.place AS place_poule, pp.victoires, pp.defaites,'
             . '       pp.sets_pour, pp.sets_contre'
             . '  FROM ' . table('participation') . ' pa'
             . '  JOIN ' . table('joueur') . ' j ON j.id = pa.joueur_id'
             . '  JOIN ' . table('classement') . ' c ON c.id = pa.classement_id'
             . '  LEFT JOIN ' . table('poule_participant') . ' pp ON pp.participation_id = pa.id'
             . '  LEFT JOIN ' . table('poule') . ' po ON po.id = pp.poule_id'
             . ' WHERE pa.phase_id = ?'
             . ($figees ? ' AND pa.place_generale IS NOT NULL ORDER BY pa.place_generale' : '');

        $st = $this->pdo->prepare($sql);
        $st->execute([$phaseId]);

        return $st->fetchAll();
    }

    /**
     * Rattache les informations d'affichage au classement calcule.
     *
     * @param  list<array<string,mixed>> $classement
     * @param  list<array<string,mixed>> $lignes
     * @return list<array<string,mixed>>
     */
    private function rattacher(array $classement, array $lignes): array
    {
        $parId = [];

        foreach ($lignes as $l) {
            $parId[(int) $l['participation_id']] = $l;
        }

        return array_map(
            static fn (array $c): array => $c + ($parId[$c['id']] ?? []),
            $classement
        );
    }
}

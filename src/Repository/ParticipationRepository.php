<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RuntimeException;

/**
 * Pointage des joueurs pour une phase.
 *
 * Remplace la feuille « A CHARGER » du classeur : pointer un joueur
 * pour une soiree. Le classement est RECOPIE au moment du pointage,
 * de sorte qu'une correction ulterieure du classement d'un joueur ne
 * reecrive pas les poules ni les handicaps d'une soiree deja jouee.
 */
final class ParticipationRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Identifiants des joueurs pointes pour la phase.
     *
     * @return list<int>
     */
    public function joueursPointes(int $phaseId): array
    {
        $st = $this->pdo->prepare(
            'SELECT joueur_id FROM ' . table('participation') . ' WHERE phase_id = ?'
        );
        $st->execute([$phaseId]);

        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Remplace le pointage de la phase par la liste fournie.
     *
     * Les joueurs absents de la liste sont retires, les nouveaux sont
     * ajoutes, les inchanges ne sont pas touches — ce qui preserve les
     * points deja calcules.
     *
     * @param  list<int> $joueurIds
     * @return array{ajoutes:int,retires:int}
     * @throws RuntimeException si les poules sont deja generees
     */
    public function remplacer(int $phaseId, array $joueurIds): array
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . table('poule') . ' WHERE phase_id = ?'
        );
        $st->execute([$phaseId]);

        if ((int) $st->fetchColumn() > 0) {
            throw new RuntimeException(
                'Les poules de cette phase sont deja generees : le pointage est verrouille.'
            );
        }

        $souhaites = array_values(array_unique(array_map('intval', $joueurIds)));
        $actuels   = $this->joueursPointes($phaseId);

        $aAjouter = array_diff($souhaites, $actuels);
        $aRetirer = array_diff($actuels, $souhaites);

        $this->pdo->beginTransaction();

        try {
            if ($aAjouter !== []) {
                // Le classement est copie depuis la fiche du joueur.
                $sql = 'INSERT INTO ' . table('participation') . ' (phase_id, joueur_id, classement_id)'
                     . ' SELECT ?, j.id, j.classement_id FROM ' . table('joueur') . ' j WHERE j.id = ?';
                $ins = $this->pdo->prepare($sql);

                foreach ($aAjouter as $id) {
                    $ins->execute([$phaseId, $id]);
                }
            }

            if ($aRetirer !== []) {
                $marques = implode(',', array_fill(0, count($aRetirer), '?'));
                $del = $this->pdo->prepare(
                    'DELETE FROM ' . table('participation')
                    . " WHERE phase_id = ? AND joueur_id IN ($marques)"
                );
                $del->execute(array_merge([$phaseId], array_values($aRetirer)));
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return ['ajoutes' => count($aAjouter), 'retires' => count($aRetirer)];
    }

    /**
     * Joueurs pointes, tries comme le serpentin les prendra :
     * classement decroissant, puis nom et prenom.
     *
     * @return list<array{joueur_id:int,nom:string,prenom:string,classement:string,rang:int}>
     */
    public function listePointee(int $phaseId): array
    {
        $sql = 'SELECT pa.joueur_id, j.nom, j.prenom, c.code AS classement, c.rang'
             . '  FROM ' . table('participation') . ' pa'
             . '  JOIN ' . table('joueur') . ' j ON j.id = pa.joueur_id'
             . '  JOIN ' . table('classement') . ' c ON c.id = pa.classement_id'
             . ' WHERE pa.phase_id = ?'
             . ' ORDER BY c.rang DESC, j.nom, j.prenom';

        $st = $this->pdo->prepare($sql);
        $st->execute([$phaseId]);

        /** @var list<array{joueur_id:int,nom:string,prenom:string,classement:string,rang:int}> $lignes */
        $lignes = $st->fetchAll();

        return $lignes;
    }

    /**
     * Reporte le classement courant d'un joueur sur sa participation.
     *
     * A appeler apres correction du classement, tant que les poules ne
     * sont pas composees : c'est cette copie qui sert au serpentin et au
     * calcul des handicaps.
     */
    public function rafraichirClassement(int $phaseId, int $joueurId): void
    {
        $st = $this->pdo->prepare(
            'UPDATE ' . table('participation') . ' pa'
            . '  JOIN ' . table('joueur') . ' j ON j.id = pa.joueur_id'
            . '   SET pa.classement_id = j.classement_id'
            . ' WHERE pa.phase_id = ? AND pa.joueur_id = ?'
            . '   AND NOT EXISTS ('
            . '       SELECT 1 FROM ' . table('poule_participant') . ' pp'
            . '        WHERE pp.participation_id = pa.id)'
        );
        $st->execute([$phaseId, $joueurId]);
    }
}

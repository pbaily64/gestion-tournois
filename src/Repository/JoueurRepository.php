<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RuntimeException;

/**
 * Acces aux joueurs.
 *
 * L'inscription est unique et vaut pour toute la saison. Le classement
 * est annonce a l'inscription et ne change plus en cours de tournoi.
 */
final class JoueurRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Tous les joueurs actifs, avec leur classement.
     *
     * Tri par nom puis prenom. Le tri SQL suffit ici : la collation
     * utf8mb4_unicode_ci range correctement les accents, contrairement
     * a un sort() PHP.
     *
     * @return list<array{id:int,nom:string,prenom:string,classement_id:int,classement:string,rang:int}>
     */
    public function tousActifs(): array
    {
        $sql = 'SELECT j.id, j.nom, j.prenom, j.classement_id,'
             . '       c.code AS classement, c.rang'
             . '  FROM ' . table('joueur') . ' j'
             . '  JOIN ' . table('classement') . ' c ON c.id = j.classement_id'
             . ' WHERE j.actif = 1'
             . ' ORDER BY j.nom, j.prenom';

        /** @var list<array{id:int,nom:string,prenom:string,classement_id:int,classement:string,rang:int}> $lignes */
        $lignes = $this->pdo->query($sql)->fetchAll();

        return $lignes;
    }

    /**
     * Cherche un homonyme. Sert a eviter les doublons a la saisie.
     *
     * @return array{id:int,nom:string,prenom:string}|null
     */
    public function parNomPrenom(string $nom, string $prenom): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, nom, prenom FROM ' . table('joueur') . ' WHERE nom = ? AND prenom = ?'
        );
        $st->execute([$nom, $prenom]);

        /** @var array{id:int,nom:string,prenom:string}|false $ligne */
        $ligne = $st->fetch();

        return $ligne === false ? null : $ligne;
    }

    /**
     * Cree un joueur et retourne son identifiant.
     *
     * @throws RuntimeException si un homonyme existe deja
     */
    public function creer(string $nom, string $prenom, int $classementId): int
    {
        $nom    = trim($nom);
        $prenom = trim($prenom);

        if ($nom === '' || $prenom === '') {
            throw new RuntimeException('Le nom et le prenom sont obligatoires.');
        }

        $existant = $this->parNomPrenom($nom, $prenom);

        if ($existant !== null) {
            throw new RuntimeException(
                sprintf('%s %s figure deja dans la liste des joueurs.', $existant['prenom'], $existant['nom'])
            );
        }

        $st = $this->pdo->prepare(
            'INSERT INTO ' . table('joueur') . ' (nom, prenom, classement_id) VALUES (?, ?, ?)'
        );
        $st->execute([$nom, $prenom, $classementId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Modifie un joueur.
     *
     * Le classement peut changer d'une saison a l'autre, ou avoir ete
     * annonce de facon erronee. Les phases deja jouees conservent leurs
     * resultats : elles gardent une copie du classement au moment du
     * pointage.
     *
     * @throws RuntimeException si un homonyme existe deja
     */
    public function modifier(int $id, string $nom, string $prenom, int $classementId): void
    {
        $nom    = trim($nom);
        $prenom = trim($prenom);

        if ($nom === '' || $prenom === '') {
            throw new RuntimeException('Le nom et le prenom sont obligatoires.');
        }

        $existant = $this->parNomPrenom($nom, $prenom);

        if ($existant !== null && (int) $existant['id'] !== $id) {
            throw new RuntimeException(
                sprintf('%s %s figure deja dans la liste des joueurs.', $prenom, $nom)
            );
        }

        $st = $this->pdo->prepare(
            'UPDATE ' . table('joueur') . ' SET nom = ?, prenom = ?, classement_id = ? WHERE id = ?'
        );
        $st->execute([$nom, $prenom, $classementId, $id]);
    }
}

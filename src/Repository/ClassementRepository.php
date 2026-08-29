<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;

/**
 * Acces aux classements AFTT.
 *
 * La table compte 18 entrees, de NC (rang 0, le plus faible) a A
 * (rang 17). C'est le `rang` qui sert au calcul du handicap et au tri
 * du serpentin, jamais le code.
 */
final class ClassementRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Tous les classements actifs, du plus fort au plus faible.
     * Cet ordre est celui attendu dans les listes deroulantes.
     *
     * @return list<array{id:int,code:string,rang:int}>
     */
    public function tous(): array
    {
        $sql = 'SELECT id, code, rang FROM ' . table('classement')
             . ' WHERE actif = 1 ORDER BY rang DESC';

        /** @var list<array{id:int,code:string,rang:int}> $lignes */
        $lignes = $this->pdo->query($sql)->fetchAll();

        return $lignes;
    }

    /** @return array{id:int,code:string,rang:int}|null */
    public function parCode(string $code): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT id, code, rang FROM ' . table('classement') . ' WHERE code = ?'
        );
        $st->execute([$code]);

        /** @var array{id:int,code:string,rang:int}|false $ligne */
        $ligne = $st->fetch();

        return $ligne === false ? null : $ligne;
    }

    public function existe(int $id): bool
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM ' . table('classement') . ' WHERE id = ? AND actif = 1'
        );
        $st->execute([$id]);

        return $st->fetchColumn() !== false;
    }
}

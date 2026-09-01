<?php

declare(strict_types=1);

namespace RMCF\Tournois\Repository;

use PDO;
use RMCF\Tournois\Formule\Flux\SourceResultats;
use RMCF\Tournois\Formule\Structure\Entite;

/**
 * `SourceResultats` branche sur MariaDB.
 *
 * C'est le seul point ou le moteur de flux touche la base. L'interface
 * qu'il implemente ne compte que huit methodes, toutes en LECTURE : le
 * moteur consomme des classements, il n'en produit pas.
 *
 * DEUX PRINCIPES GOUVERNENT CE FICHIER.
 *
 * Premier — le classement lu ici vient de `classement_calcule`, qui est
 * un CACHE (RG-55). Si le cache est vide ou perime, la bonne reponse
 * n'est pas de recalculer a la volee dans un repository : c'est de
 * rendre une phase « non close », ce qui suspend proprement les flux qui
 * en dependent. Un flux qui s'appuierait sur un classement partiel
 * enverrait des joueurs dans le mauvais tableau.
 *
 * Second — le cache est charge une fois par phase et conserve pour la
 * duree de la requete. Le moteur de flux interroge la meme phase des
 * dizaines de fois (une par groupe, une par selecteur) ; sans
 * memoisation, generer un tournoi de dix poules produirait une centaine
 * d'aller-retours SQL pour la meme information.
 */
final class ResultatsRepository implements SourceResultats
{
    /** @var array<string,array<string,list<string>>> phase => groupe => refs */
    private array $classements = [];

    /** @var array<string,list<string>> */
    private array $globaux = [];

    /** @var array<string,array<int,array{vainqueurs:list<string>,perdants:list<string>}>> */
    private array $tours = [];

    /** @var array<string,array<string,int>> */
    private array $defaites = [];

    /** @var array<string,Entite> */
    private array $entites = [];

    /** @var array<string,bool> */
    private array $closes = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $tournoiId,
    ) {
    }

    // -----------------------------------------------------------------
    // Lecture
    // -----------------------------------------------------------------

    public function groupes(string $phase): array
    {
        $this->chargerPhase($phase);

        return array_keys($this->classements[$phase] ?? []);
    }

    public function classementGroupe(string $phase, string $groupe): array
    {
        $this->chargerPhase($phase);

        return $this->classements[$phase][$groupe] ?? [];
    }

    public function classementGlobal(string $phase): array
    {
        $this->chargerPhase($phase);

        return $this->globaux[$phase] ?? [];
    }

    public function perdantsTour(string $phase, int $tour): array
    {
        $this->chargerTours($phase);

        return $this->tours[$phase][$tour]['perdants'] ?? [];
    }

    public function vainqueursTour(string $phase, int $tour): array
    {
        $this->chargerTours($phase);

        return $this->tours[$phase][$tour]['vainqueurs'] ?? [];
    }

    public function defaites(string $phase, string $entite): int
    {
        $this->chargerTours($phase);

        return $this->defaites[$phase][$entite] ?? 0;
    }

    public function entite(string $ref): ?Entite
    {
        if ($this->entites === []) {
            $this->chargerEntites();
        }

        return $this->entites[$ref] ?? null;
    }

    /**
     * Une phase est close quand tous ses resultats sont saisis ET que
     * son classement a ete calcule.
     *
     * Les deux conditions comptent. Une phase dont les matchs sont tous
     * saisis mais dont le cache de classement n'a pas ete reconstruit
     * n'est PAS exploitable par un flux : elle renverrait un classement
     * perime, ce qui est pire qu'un classement absent parce que
     * l'erreur passerait inapercue.
     */
    public function estClose(string $phase): bool
    {
        if (isset($this->closes[$phase])) {
            return $this->closes[$phase];
        }

        $st = $this->pdo->prepare(
            'SELECT COUNT(*) AS total,'
            . ' SUM(r.etat IN (\'terminee\', \'non_disputee\')) AS finies,'
            . ' (SELECT COUNT(*) FROM ' . table('classement_calcule') . ' cc'
            . '  WHERE cc.phase_id = p.id) AS classes'
            . ' FROM ' . table('phase') . ' p'
            . ' LEFT JOIN ' . table('rencontre') . ' r ON r.phase_id = p.id'
            . ' WHERE p.tournoi_id = ? AND p.code = ?'
            . ' GROUP BY p.id'
        );

        $st->execute([$this->tournoiId, $phase]);
        $ligne = $st->fetch(PDO::FETCH_ASSOC);

        if ($ligne === false) {
            return $this->closes[$phase] = false;
        }

        $total   = (int) ($ligne['total'] ?? 0);
        $finies  = (int) ($ligne['finies'] ?? 0);
        $classes = (int) ($ligne['classes'] ?? 0);

        return $this->closes[$phase] = $total > 0 && $finies === $total && $classes > 0;
    }

    /** Vide la memoisation — a appeler apres toute ecriture de resultat. */
    public function oublier(?string $phase = null): void
    {
        if ($phase === null) {
            $this->classements = [];
            $this->globaux     = [];
            $this->tours       = [];
            $this->defaites    = [];
            $this->closes      = [];

            return;
        }

        unset(
            $this->classements[$phase],
            $this->globaux[$phase],
            $this->tours[$phase],
            $this->defaites[$phase],
            $this->closes[$phase],
        );
    }

    // -----------------------------------------------------------------
    // Chargement
    // -----------------------------------------------------------------

    private function chargerPhase(string $phase): void
    {
        if (isset($this->classements[$phase])) {
            return;
        }

        $this->classements[$phase] = [];
        $this->globaux[$phase]     = [];

        $st = $this->pdo->prepare(
            'SELECT g.code AS groupe, cc.inscription_id, cc.rang'
            . ' FROM ' . table('classement_calcule') . ' cc'
            . ' JOIN ' . table('phase') . ' p ON p.id = cc.phase_id'
            . ' LEFT JOIN ' . table('groupe') . ' g ON g.id = cc.groupe_id'
            . ' WHERE p.tournoi_id = ? AND p.code = ?'
            . ' ORDER BY g.ordre, cc.rang'
        );

        $st->execute([$this->tournoiId, $phase]);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $ref = (string) $ligne['inscription_id'];

            // groupe_id NULL = classement inter-groupes de la phase.
            if ($ligne['groupe'] === null) {
                $this->globaux[$phase][] = $ref;
                continue;
            }

            $this->classements[$phase][(string) $ligne['groupe']][] = $ref;
        }

        if ($this->globaux[$phase] === []) {
            $this->globaux[$phase] = $this->entrelacer($this->classements[$phase]);
        }
    }

    /**
     * Classement inter-groupes de repli : tous les premiers, puis tous
     * les deuxiemes, etc.
     *
     * Ce n'est pas un vrai classement inter-poules — celui-la exige une
     * cascade dediee (§7.6) et se stocke avec `groupe_id` a NULL. C'est
     * un ORDRE DE PLACEMENT raisonnable en son absence, et il est
     * suffisant pour les selecteurs qui n'ont besoin que d'un ordre.
     *
     * @param  array<string,list<string>> $groupes
     * @return list<string>
     */
    private function entrelacer(array $groupes): array
    {
        $parPlace = [];

        foreach ($groupes as $classement) {
            foreach ($classement as $place => $ref) {
                $parPlace[$place][] = $ref;
            }
        }

        ksort($parPlace);
        $global = [];

        foreach ($parPlace as $refs) {
            $global = [...$global, ...$refs];
        }

        return $global;
    }

    /**
     * Vainqueurs et perdants tour par tour, pour les tableaux.
     *
     * Une rencontre `non_disputee` (RG-11) ne produit ni vainqueur ni
     * perdant : elle ne doit alimenter aucune consolante. Un forfait,
     * lui, en produit un de chaque — c'est toute la difference entre les
     * deux motifs (RG-82), et elle se voit jusque dans cette requete.
     */
    private function chargerTours(string $phase): void
    {
        if (isset($this->tours[$phase])) {
            return;
        }

        $this->tours[$phase]    = [];
        $this->defaites[$phase] = [];

        $st = $this->pdo->prepare(
            'SELECT COALESCE(t.numero, 1) AS tour, r.camp_a_id, r.camp_b_id,'
            . ' r.score_a, r.score_b, r.etat'
            . ' FROM ' . table('rencontre') . ' r'
            . ' JOIN ' . table('phase') . ' p ON p.id = r.phase_id'
            . ' LEFT JOIN ' . table('tour') . ' t ON t.id = r.tour_id'
            . ' WHERE p.tournoi_id = ? AND p.code = ?'
            . '   AND r.etat = \'terminee\''
            . '   AND r.camp_a_id IS NOT NULL AND r.camp_b_id IS NOT NULL'
            . ' ORDER BY tour, r.ordre'
        );

        $st->execute([$this->tournoiId, $phase]);

        $membres = $this->membresParCamp();

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $tour = (int) $ligne['tour'];

            if ((int) $ligne['score_a'] === (int) $ligne['score_b']) {
                continue; // nul : aucun perdant a router
            }

            $gagnant = (int) $ligne['score_a'] > (int) $ligne['score_b']
                ? (int) $ligne['camp_a_id']
                : (int) $ligne['camp_b_id'];

            $perdant = $gagnant === (int) $ligne['camp_a_id']
                ? (int) $ligne['camp_b_id']
                : (int) $ligne['camp_a_id'];

            foreach ($membres[$gagnant] ?? [] as $ref) {
                $this->tours[$phase][$tour]['vainqueurs'][] = $ref;
            }

            foreach ($membres[$perdant] ?? [] as $ref) {
                $this->tours[$phase][$tour]['perdants'][] = $ref;
                $this->defaites[$phase][$ref] = ($this->defaites[$phase][$ref] ?? 0) + 1;
            }
        }

        foreach ($this->tours[$phase] as $tour => $detail) {
            $this->tours[$phase][$tour] = [
                'vainqueurs' => $detail['vainqueurs'] ?? [],
                'perdants'   => $detail['perdants'] ?? [],
            ];
        }
    }

    /**
     * camp_id => references d'inscription.
     *
     * En simple, un camp compte un membre et la reference est celle de
     * l'inscription. En double et en equipe, le camp en compte
     * plusieurs : c'est la que la distinction entre l'entite classee et
     * le camp qui joue devient concrete (RG-14).
     *
     * @return array<int,list<string>>
     */
    private function membresParCamp(): array
    {
        $st = $this->pdo->prepare(
            'SELECT cm.camp_id, cm.inscription_id'
            . ' FROM ' . table('camp_membre') . ' cm'
            . ' JOIN ' . table('camp') . ' c ON c.id = cm.camp_id'
            . ' WHERE c.tournoi_id = ?'
            . ' ORDER BY cm.camp_id, cm.ordre'
        );

        $st->execute([$this->tournoiId]);

        $membres = [];

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $membres[(int) $ligne['camp_id']][] = (string) $ligne['inscription_id'];
        }

        return $membres;
    }

    /**
     * Charge les inscrits en entites, avec leur classement GELE.
     *
     * RG-02 et RG-75 : on lit `rang_gele`, jamais le classement courant
     * du joueur. C'est ce qui garantit qu'un handicap calcule en
     * novembre vaut encore la meme chose recalcule en mars.
     */
    private function chargerEntites(): void
    {
        $st = $this->pdo->prepare(
            'SELECT i.id, i.rang_gele, i.tete_de_serie, i.vies_restantes,'
            . ' j.nom, j.prenom, j.club, j.famille'
            . ' FROM ' . table('inscription') . ' i'
            . ' JOIN ' . table('joueur') . ' j ON j.id = i.joueur_id'
            . ' WHERE i.tournoi_id = ? AND i.etat <> \'forfait\''
            . ' ORDER BY COALESCE(i.tete_de_serie, 9999), i.rang_gele DESC, j.nom'
        );

        $st->execute([$this->tournoiId]);

        $rang = 1;

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $ref = (string) $ligne['id'];

            $this->entites[$ref] = new Entite(
                ref: $ref,
                libelle: trim(($ligne['prenom'] ?? '') . ' ' . ($ligne['nom'] ?? '')),
                rang: (int) ($ligne['tete_de_serie'] ?? 0) ?: $rang,
                classementGele: (int) $ligne['rang_gele'],
                viesRestantes: (int) $ligne['vies_restantes'],
                club: $ligne['club'] !== null ? (string) $ligne['club'] : null,
                famille: $ligne['famille'] !== null ? (string) $ligne['famille'] : null,
            );

            $rang++;
        }
    }

    /**
     * Le plateau des inscrits, ordonne par tete de serie.
     *
     * @return list<Entite>
     */
    public function inscrits(): array
    {
        if ($this->entites === []) {
            $this->chargerEntites();
        }

        return array_values($this->entites);
    }
}

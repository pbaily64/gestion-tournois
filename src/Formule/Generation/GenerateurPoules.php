<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Generation;

use RMCF\Tournois\Formule\Parametres;
use RMCF\Tournois\Formule\Structure\Appariement;
use RMCF\Tournois\Formule\Structure\Emplacement;
use RMCF\Tournois\Formule\Structure\Entite;
use RMCF\Tournois\Formule\Structure\PhaseGeneree;
use RMCF\Tournois\Formule\Structure\Plateau;

/**
 * Brique 1 — la poule (§3.1).
 *
 * Trois decisions successives, dans cet ordre :
 *
 *   1. COMBIEN DE POULES — `nb_groupes` / `taille_groupe`. En `auto`,
 *      on cherche la repartition la plus proche de 4 joueurs par poule
 *      (norme ITTF) qui respecte les bornes 3..8 et la tolerance d'ecart.
 *   2. QUI VA OU — le serpentin (`p = i mod 2n`, puis `poule = p` si
 *      `p < n`, sinon `2n-1-p`). C'est la regle universelle : elle
 *      garantit que la somme des forces est aussi egale que possible
 *      d'une poule a l'autre.
 *   3. DANS QUEL ORDRE ON JOUE — les sequences officielles de l'annexe A,
 *      eventuellement reordonnees pour que la partie decisive tombe en
 *      dernier (ITTF 3.7.5.5).
 *
 * Les criteres de separation (meme club, meme famille, meme poule au
 * tour precedent) sont traites APRES le serpentin, par echanges locaux
 * entre poules a l'interieur d'un meme rang de serpentin. Echanger deux
 * joueurs de meme rang preserve l'equilibre des forces — c'est la seule
 * facon de separer sans casser ce que le serpentin vient de construire.
 */
final class GenerateurPoules implements Generateur
{
    public const TAILLE_MIN = 3;
    public const TAILLE_MAX = 8;

    /** Taille visee quand rien n'est impose (norme ITTF). */
    private const TAILLE_CIBLE = 4;

    /**
     * @param list<array{0:string,1:string}> $confrontationsAnterieures
     *        paires deja disputees, pour `rejouer_confrontations… = faux`
     */
    public function __construct(
        private readonly array $confrontationsAnterieures = [],
        private readonly int $graine = 0,
    ) {
    }

    public function type(): string
    {
        return 'poules';
    }

    public function generer(string $phase, Plateau $entrants, Parametres $p): PhaseGeneree
    {
        $avertissements = [];
        $effectif       = $entrants->effectif();

        if ($effectif === 0) {
            return new PhaseGeneree($phase, 'poules', [], [], [], ['Aucun inscrit : phase vide.']);
        }

        $tailles = $this->tailles($effectif, $p, $avertissements);
        $nbPoules = count($tailles);

        // --- placement -------------------------------------------------
        $ordonne = match ($p->texte('methode_placement', 'serpentin')) {
            'tirage'               => $entrants->melanger($this->graine),
            'serpentin_puis_tirage' => $entrants->parRang(),
            'manuel'               => $entrants,
            default                => $entrants->parRang(),
        };

        $poules = $p->texte('methode_placement', 'serpentin') === 'manuel'
            ? $this->repartirSequentiel($ordonne, $tailles)
            : $this->repartirSerpentin($ordonne, $tailles);

        $poules = $this->appliquerSeparations($poules, $p, $avertissements);

        // --- appariements ----------------------------------------------
        $groupes      = [];
        $appariements = [];

        foreach ($poules as $indice => $membres) {
            $libelle            = self::libellePoule($indice);
            $groupes[$libelle]  = array_map(static fn (Entite $e): string => $e->ref, $membres);
            $appariements       = [
                ...$appariements,
                ...$this->appariementsPoule($phase, $libelle, $membres, $p),
            ];
        }

        $volume = count(array_filter(
            $appariements,
            static fn (Appariement $a): bool => ! $a->estExempt()
        ));

        if ($volume === 0 && $effectif > 1) {
            $avertissements[] = 'Aucune partie generee : verifier les tailles de poules.';
        }

        return new PhaseGeneree(
            phase: $phase,
            type: 'poules',
            groupes: $groupes,
            appariements: $appariements,
            tours: [1 => 'Poules'],
            avertissements: $avertissements,
            meta: [
                'tailles'      => $tailles,
                'nb_poules'    => $nbPoules,
                'nb_qualifies' => $p->entier('nb_qualifies', 2),
            ],
        );
    }

    public function volume(int $effectif, Parametres $p): int
    {
        $ignore  = [];
        $tailles = $this->tailles($effectif, $p, $ignore);
        $total   = 0;

        foreach ($tailles as $taille) {
            $total += OrdreParties::nombreParties($taille);
        }

        return $total;
    }

    // -----------------------------------------------------------------
    // Dimensionnement
    // -----------------------------------------------------------------

    /**
     * Tailles des poules, de la plus grande a la plus petite.
     *
     * @param  list<string> $avertissements
     * @return list<int>
     */
    private function tailles(int $effectif, Parametres $p, array &$avertissements): array
    {
        $tolerance = max(0, $p->entier('tolerance_taille', 1) ?? 1);
        $nbGroupes = $p->estAuto('nb_groupes') ? null : $p->entier('nb_groupes');
        $taille    = $p->estAuto('taille_groupe') || $p->texte('taille_groupe') === 'equilibree'
            ? null
            : $p->entier('taille_groupe');

        if ($nbGroupes === null && $taille !== null && $taille > 0) {
            $nbGroupes = max(1, (int) ceil($effectif / $taille));
        }

        if ($nbGroupes === null) {
            $nbGroupes = $this->nbPoulesOptimal($effectif, $tolerance, $avertissements);
        }

        $nbGroupes = max(1, min($nbGroupes, $effectif));
        $tailles   = self::equilibrer($effectif, $nbGroupes);

        $min = min($tailles);
        $max = max($tailles);

        if ($min < self::TAILLE_MIN && $effectif >= self::TAILLE_MIN) {
            $avertissements[] = sprintf(
                'Poule de %d joueur(s) : en dessous du minimum usuel de %d.',
                $min,
                self::TAILLE_MIN
            );
        }

        if ($max > self::TAILLE_MAX) {
            $avertissements[] = sprintf(
                'Poule de %d joueurs : au-dela du maximum de %d, soit %d parties pour cette seule poule.',
                $max,
                self::TAILLE_MAX,
                OrdreParties::nombreParties($max)
            );
        }

        if ($max - $min > $tolerance) {
            $avertissements[] = sprintf(
                'Ecart de taille entre poules : %d, tolerance fixee a %d.',
                $max - $min,
                $tolerance
            );
        }

        return $tailles;
    }

    /**
     * Cherche le nombre de poules le plus proche de la taille cible.
     *
     * @param list<string> $avertissements
     */
    private function nbPoulesOptimal(int $effectif, int $tolerance, array &$avertissements): int
    {
        $meilleur = null;
        $score    = PHP_FLOAT_MAX;

        for ($nb = 1; $nb <= max(1, intdiv($effectif, self::TAILLE_MIN)); $nb++) {
            $tailles = self::equilibrer($effectif, $nb);
            $min     = min($tailles);
            $max     = max($tailles);

            if ($min < self::TAILLE_MIN || $max > self::TAILLE_MAX) {
                continue;
            }

            if ($max - $min > $tolerance) {
                continue;
            }

            $candidat = abs(($effectif / $nb) - self::TAILLE_CIBLE);

            if ($candidat < $score) {
                $score    = $candidat;
                $meilleur = $nb;
            }
        }

        if ($meilleur !== null) {
            return $meilleur;
        }

        $avertissements[] = sprintf(
            'Aucune repartition en poules de %d a %d joueurs pour %d inscrits : repartition forcee.',
            self::TAILLE_MIN,
            self::TAILLE_MAX,
            $effectif
        );

        return max(1, (int) round($effectif / self::TAILLE_CIBLE));
    }

    /**
     * Repartit `$effectif` en `$nb` parts aussi egales que possible,
     * les plus grandes d'abord.
     *
     * @return list<int>
     */
    public static function equilibrer(int $effectif, int $nb): array
    {
        $nb      = max(1, $nb);
        $base    = intdiv($effectif, $nb);
        $reste   = $effectif % $nb;
        $tailles = [];

        for ($i = 0; $i < $nb; $i++) {
            $tailles[] = $base + ($i < $reste ? 1 : 0);
        }

        return $tailles;
    }

    // -----------------------------------------------------------------
    // Placement
    // -----------------------------------------------------------------

    /**
     * Le serpentin (S-rule) : `p = i mod 2n`, poule = `p` si `p < n`,
     * sinon `2n-1-p`.
     *
     * @param  list<int> $tailles
     * @return list<list<Entite>>
     */
    private function repartirSerpentin(Plateau $plateau, array $tailles): array
    {
        $nb     = count($tailles);
        $poules = array_fill(0, $nb, []);
        $restant = $tailles;
        $i      = 0;

        foreach ($plateau->entites() as $entite) {
            $cible = null;

            // On avance dans le serpentin jusqu'a trouver une poule qui
            // n'est pas deja pleine : c'est ce qui gere les tailles
            // inegales sans casser l'alternance.
            for ($essai = 0; $essai < 2 * $nb * max(1, $nb); $essai++) {
                $p    = ($i + $essai) % (2 * $nb);
                $rang = $p < $nb ? $p : (2 * $nb - 1 - $p);

                if ($restant[$rang] > 0) {
                    $cible = $rang;
                    $i     = $i + $essai + 1;
                    break;
                }
            }

            if ($cible === null) {
                continue;
            }

            $poules[$cible][] = $entite;
            $restant[$cible]--;
        }

        return $poules;
    }

    /**
     * Remplissage sequentiel : les n premiers en poule A, etc.
     *
     * C'est le mode `manuel` — l'organisateur a deja ordonne le plateau
     * comme il veut, on ne redistribue rien.
     *
     * @param  list<int> $tailles
     * @return list<list<Entite>>
     */
    private function repartirSequentiel(Plateau $plateau, array $tailles): array
    {
        $poules  = [];
        $entites = $plateau->entites();
        $offset  = 0;

        foreach ($tailles as $taille) {
            $poules[] = array_slice($entites, $offset, $taille);
            $offset  += $taille;
        }

        return $poules;
    }

    /**
     * Separe ce qui doit l'etre, par echanges a rang de serpentin egal.
     *
     * @param  list<list<Entite>> $poules
     * @param  list<string>       $avertissements
     * @return list<list<Entite>>
     */
    private function appliquerSeparations(array $poules, Parametres $p, array &$avertissements): array
    {
        $criteres = $p->liste('criteres_separation');

        if ($criteres === []) {
            return $poules;
        }

        $conflits = 0;

        // On parcourt rang par rang : a l'interieur d'un rang, toutes
        // les entites ont ete tirees du meme segment du plateau, donc
        // les echanger ne modifie pas l'equilibre des forces.
        $profondeur = max(array_map('count', $poules) ?: [0]);

        for ($rang = 0; $rang < $profondeur; $rang++) {
            foreach ($poules as $i => $poule) {
                if (! isset($poule[$rang])) {
                    continue;
                }

                if (! $this->enConflit($poule[$rang], $poule, $criteres, $rang)) {
                    continue;
                }

                $echange = $this->chercherEchange($poules, $i, $rang, $criteres);

                if ($echange === null) {
                    $conflits++;
                    continue;
                }

                [$j, $k] = $echange;
                $tmp                = $poules[$i][$rang];
                $poules[$i][$rang]  = $poules[$j][$k];
                $poules[$j][$k]     = $tmp;
            }
        }

        if ($conflits > 0) {
            $avertissements[] = sprintf(
                '%d conflit(s) de separation non resolu(s) : aucun echange ne preservait l\'equilibre des poules.',
                $conflits
            );
        }

        return $poules;
    }

    /**
     * @param list<Entite> $poule
     * @param list<string> $criteres
     */
    private function enConflit(Entite $entite, array $poule, array $criteres, int $position): bool
    {
        foreach ($poule as $i => $autre) {
            if ($i === $position) {
                continue;
            }

            foreach ($criteres as $critere) {
                $conflit = match ($critere) {
                    'meme_club'    => $entite->club !== null && $entite->club === $autre->club,
                    'meme_famille' => $entite->famille !== null && $entite->famille === $autre->famille,
                    'meme_poule_phase_precedente' => $entite->origine !== null
                        && $entite->origine === $autre->origine,
                    default => false,
                };

                if ($conflit) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Trouve un echange qui resout le conflit sans en creer un autre.
     *
     * @param  list<list<Entite>> $poules
     * @param  list<string>       $criteres
     * @return array{0:int,1:int}|null
     */
    private function chercherEchange(array $poules, int $source, int $rang, array $criteres): ?array
    {
        $candidat = $poules[$source][$rang];

        foreach ($poules as $j => $poule) {
            if ($j === $source || ! isset($poule[$rang])) {
                continue;
            }

            $autre = $poule[$rang];

            $pouleSourceApres = $poules[$source];
            $pouleSourceApres[$rang] = $autre;

            $pouleCibleApres = $poule;
            $pouleCibleApres[$rang] = $candidat;

            if ($this->enConflit($autre, $pouleSourceApres, $criteres, $rang)) {
                continue;
            }

            if ($this->enConflit($candidat, $pouleCibleApres, $criteres, $rang)) {
                continue;
            }

            return [$j, $rang];
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Appariements
    // -----------------------------------------------------------------

    /**
     * @param  list<Entite> $membres
     * @return list<Appariement>
     */
    private function appariementsPoule(string $phase, string $libelle, array $membres, Parametres $p): array
    {
        $taille = count($membres);

        if ($taille < 2) {
            return [];
        }

        $sequence = OrdreParties::pour($taille);

        if ($p->booleen('derniere_partie_decisive')) {
            $sequence = OrdreParties::decisiveEnDernier(
                $sequence,
                $taille,
                $p->entier('nb_qualifies', 2) ?? 2
            );
        }

        if ($p->texte('ordre_parties', 'officiel') === 'libre') {
            $sequence = $this->ordreLibre($taille);
        }

        $rejouer = $p->estRenseigne('rejouer_confrontations_deja_disputees')
            ? $p->booleen('rejouer_confrontations_deja_disputees')
            : true;

        $appariements = [];
        $ordre        = 1;

        foreach ($sequence as $partie) {
            $a = $membres[OrdreParties::indiceLettre($partie[0])] ?? null;
            $b = $membres[OrdreParties::indiceLettre($partie[1])] ?? null;

            if ($a === null || $b === null) {
                continue;
            }

            if (! $rejouer && $this->dejaDisputee($a->ref, $b->ref)) {
                continue;
            }

            $arbitre = isset($partie[2]) && $partie[2] !== null
                ? ($membres[OrdreParties::indiceLettre($partie[2])] ?? null)
                : null;

            $appariements[] = new Appariement(
                id: sprintf('%s-%s-%02d', $phase, $libelle, $ordre),
                phase: $phase,
                a: Emplacement::entite($a),
                b: Emplacement::entite($b),
                tour: 1,
                ordre: $ordre,
                groupe: $libelle,
                role: Appariement::ROLE_POULE,
                libelle: sprintf('Poule %s — partie %d', $libelle, $ordre),
                enjeu: $arbitre !== null ? 'Arbitre : ' . $arbitre->libelle : null,
            );

            $ordre++;
        }

        return $appariements;
    }

    /** @return list<array{0:string,1:string,2:?string}> */
    private function ordreLibre(int $taille): array
    {
        $sequence = [];

        for ($i = 0; $i < $taille; $i++) {
            for ($j = $i + 1; $j < $taille; $j++) {
                $sequence[] = [OrdreParties::lettre($i), OrdreParties::lettre($j), null];
            }
        }

        return $sequence;
    }

    private function dejaDisputee(string $a, string $b): bool
    {
        foreach ($this->confrontationsAnterieures as $paire) {
            if (($paire[0] === $a && $paire[1] === $b) || ($paire[0] === $b && $paire[1] === $a)) {
                return true;
            }
        }

        return false;
    }

    /** Poule 0 => « A ». Au-dela de Z, on passe a AA, AB… */
    public static function libellePoule(int $indice): string
    {
        $libelle = '';

        do {
            $libelle = chr(ord('A') + ($indice % 26)) . $libelle;
            $indice  = intdiv($indice, 26) - 1;
        } while ($indice >= 0);

        return $libelle;
    }
}

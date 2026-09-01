<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule;

use InvalidArgumentException;
use RMCF\Tournois\Domain\FormatMatch;

/**
 * Format d'une partie (annexe C.6).
 *
 * Generalise l'enumeration Domain\FormatMatch, qui ne connait que les
 * trois formats du Mickey By Night. Ici tout est parametrable : nombre
 * de manches, manches seches ou gagnantes, points par manche, deux
 * points d'ecart, manche unique a 31 ou 42 points, jeu au temps.
 *
 * DEUX PROPRIETES DECIDENT DU CLASSEMENT
 *
 * manchesComparables() — vrai en format sec : toute partie compte le
 * meme nombre de manches, donc les manches gagnees se comparent d'un
 * joueur a l'autre sans correction. Faux en manches gagnantes : celui
 * qui gagne 3-2 accumule plus de manches que celui qui ecrase 3-0.
 *
 * victoiresExploitables() — faux en manches seches en nombre PAIR :
 * le 2-2 existe, il n'y a pas toujours de vainqueur, le critere
 * « victoires » n'a plus de sens (RG-52). C'est la correction apportee
 * par la version 1.1 du document : la version 1.0 generalisait a tort
 * le cas des trois manches seches, ou il y a toujours un vainqueur.
 *
 * RG-40 — le seuil d'egalite et le changement de cote SUIVENT le
 * nombre de points par manche. Une manche a 21 points a son egalite a
 * 20-20 et son changement de cote a 10, pas a 10-10 et 5.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class FormatPartie
{
    public const MANCHES_GAGNANTES = 'manches_gagnantes';
    public const MANCHES_SECHES    = 'manches_seches';
    public const MANCHE_UNIQUE     = 'manche_unique';
    public const AU_TEMPS          = 'au_temps';
    public const SCORE_CIBLE       = 'score_cible';

    public function __construct(
        public readonly string $type = self::MANCHES_GAGNANTES,
        public readonly int $nbManches = 3,
        public readonly int $pointsParManche = 11,
        public readonly bool $deuxPointsEcart = true,
        public readonly ?int $plafondManche = null,
        public readonly ?int $duree = null,
        public readonly string $acceleration = 'autorisee',
        public readonly int $accelerationApres = 10,
        public readonly ?int $seuilEgaliteImpose = null,
        public readonly ?int $changementCoteImpose = null,
    ) {
        if ($this->nbManches < 1) {
            throw new InvalidArgumentException('Un format compte au moins une manche.');
        }

        if ($this->pointsParManche < 1) {
            throw new InvalidArgumentException('Une manche se joue en au moins un point.');
        }

        if ($this->type === self::AU_TEMPS && $this->duree === null) {
            throw new InvalidArgumentException('Un format au temps exige une duree.');
        }
    }

    /** Construit le format effectif a partir d'une chaine de parametres. */
    public static function depuisParametres(Parametres $p): self
    {
        return new self(
            type: $p->texte('type_format', self::MANCHES_GAGNANTES),
            nbManches: $p->entier('nb_manches', 3) ?? 3,
            pointsParManche: $p->entier('points_par_manche', 11) ?? 11,
            deuxPointsEcart: $p->booleen('deux_points_ecart'),
            plafondManche: $p->entier('plafond_manche'),
            duree: $p->entier('duree'),
            acceleration: $p->texte('acceleration', 'autorisee'),
            accelerationApres: $p->entier('acceleration_apres', 10) ?? 10,
            seuilEgaliteImpose: $p->estAuto('seuil_egalite') ? null : $p->entier('seuil_egalite'),
            changementCoteImpose: $p->estAuto('changement_cote_manche_decisive')
                ? null
                : $p->entier('changement_cote_manche_decisive'),
        );
    }

    /** Reprend un format historique du module, pour la compatibilite. */
    public static function depuisFormatMatch(FormatMatch $format): self
    {
        return match ($format) {
            FormatMatch::TroisSetsSecs     => new self(self::MANCHES_SECHES, 3),
            FormatMatch::DeuxSetsGagnants  => new self(self::MANCHES_GAGNANTES, 2),
            FormatMatch::TroisSetsGagnants => new self(self::MANCHES_GAGNANTES, 3),
        };
    }

    /**
     * Rend l'equivalent historique, ou null si le format n'en a pas.
     *
     * Permet aux ecrans deja ecrits (encodage, impression) de continuer
     * a fonctionner tant qu'ils n'ont pas ete generalises.
     */
    public function versFormatMatch(): ?FormatMatch
    {
        if ($this->pointsParManche !== 11 || !$this->deuxPointsEcart) {
            return null;
        }

        return match (true) {
            $this->type === self::MANCHES_SECHES && $this->nbManches === 3     => FormatMatch::TroisSetsSecs,
            $this->type === self::MANCHES_GAGNANTES && $this->nbManches === 2  => FormatMatch::DeuxSetsGagnants,
            $this->type === self::MANCHES_GAGNANTES && $this->nbManches === 3  => FormatMatch::TroisSetsGagnants,
            default                                                            => null,
        };
    }

    /** Manches necessaires pour gagner, ou null si toutes se jouent. */
    public function manchesPourGagner(): ?int
    {
        return match ($this->type) {
            self::MANCHES_GAGNANTES => $this->nbManches,
            self::MANCHE_UNIQUE     => 1,
            default                 => null,
        };
    }

    /** Nombre maximum de manches, donc de cases de score a imprimer. */
    public function nombreDeCases(): int
    {
        return match ($this->type) {
            self::MANCHES_GAGNANTES => 2 * $this->nbManches - 1,
            self::MANCHES_SECHES    => $this->nbManches,
            self::MANCHE_UNIQUE, self::AU_TEMPS, self::SCORE_CIBLE => 1,
            default                 => $this->nbManches,
        };
    }

    /**
     * Les manches gagnees sont-elles comparables entre parties ?
     *
     * Vrai uniquement en format sec et en manche unique : le volume de
     * manches y est fixe. C'est ce qui autorise la difference de
     * manches comme critere de classement (section 7.6).
     */
    public function manchesComparables(): bool
    {
        return in_array($this->type, [self::MANCHES_SECHES, self::MANCHE_UNIQUE], true);
    }

    /**
     * Le critere « nombre de victoires » a-t-il un sens ? (RG-52)
     *
     * En manches seches en nombre pair, l'egalite est possible — 2-2 en
     * quatre manches seches — et le critere doit etre retire de la
     * cascade, avec avertissement a l'organisateur.
     */
    public function victoiresExploitables(): bool
    {
        return !($this->type === self::MANCHES_SECHES && $this->nbManches % 2 === 0);
    }

    /** Une partie peut-elle se terminer sur une egalite ? */
    public function egalitePossible(): bool
    {
        return !$this->victoiresExploitables() || $this->type === self::AU_TEMPS;
    }

    /** RG-40 : seuil d'egalite, deduit des points par manche. */
    public function seuilEgalite(): int
    {
        return $this->seuilEgaliteImpose ?? ($this->pointsParManche - 1);
    }

    /** RG-40 : changement de cote dans la manche decisive. */
    public function changementCoteMancheDecisive(): int
    {
        return $this->changementCoteImpose ?? intdiv($this->pointsParManche, 2);
    }

    /**
     * Verifie un score exprime en manches.
     *
     * @return string|null message d'erreur, ou null si le score est valide
     */
    public function verifierManches(int $a, int $b): ?string
    {
        if ($a < 0 || $b < 0) {
            return 'Un nombre de manches ne peut pas etre negatif.';
        }

        $total   = $a + $b;
        $gagnant = max($a, $b);
        $perdant = min($a, $b);

        if ($this->type === self::MANCHES_SECHES) {
            if ($total !== $this->nbManches) {
                return sprintf(
                    'En %d manches seches, les %d manches se jouent.',
                    $this->nbManches,
                    $this->nbManches
                );
            }

            if ($a === $b && $this->victoiresExploitables()) {
                return 'Une partie ne peut pas se terminer sur une egalite.';
            }

            return null;
        }

        if ($this->type === self::MANCHE_UNIQUE || $this->type === self::AU_TEMPS) {
            return $total === 1 ? null : 'Ce format ne compte qu\'une seule manche.';
        }

        if ($a === $b) {
            return 'Une partie ne peut pas se terminer sur une egalite.';
        }

        if ($gagnant !== $this->nbManches) {
            return sprintf('Le vainqueur doit remporter exactement %d manches.', $this->nbManches);
        }

        if ($perdant >= $this->nbManches) {
            return 'Le perdant ne peut pas atteindre le nombre de manches gagnantes.';
        }

        return null;
    }

    /**
     * Verifie une manche isolee.
     *
     * Regle generale : la manche va au nombre de points annonce, avec
     * deux points d'ecart si l'option est active. Au-dela du seuil,
     * l'ecart est donc exactement de deux, sauf plafond.
     */
    public function verifierManche(int $a, int $b): ?string
    {
        if ($a < 0 || $b < 0) {
            return 'un score ne peut pas etre negatif.';
        }

        $gagnant = max($a, $b);
        $perdant = min($a, $b);
        $cible   = $this->pointsParManche;

        if ($gagnant < $cible) {
            return sprintf('la manche doit aller au moins a %d points.', $cible);
        }

        if (!$this->deuxPointsEcart) {
            return $gagnant === $cible ? null : sprintf('la manche s\'arrete a %d points.', $cible);
        }

        if ($gagnant === $cible) {
            return $perdant <= $cible - 2
                ? null
                : sprintf('a %d partout, il faut deux points d\'ecart.', $cible - 1);
        }

        if ($this->plafondManche !== null && $gagnant > $this->plafondManche) {
            return sprintf('la manche ne peut pas depasser %d points.', $this->plafondManche);
        }

        if ($this->plafondManche !== null && $gagnant === $this->plafondManche) {
            return null; // au plafond, un point d'ecart suffit
        }

        return $perdant === $gagnant - 2
            ? null
            : sprintf('au-dela de %d points, l\'ecart doit etre exactement de deux.', $cible);
    }

    /**
     * Tous les scores en manches possibles, du plus net au plus serre
     * puis en miroir. Sert aux boutons de saisie rapide.
     *
     * @return list<array{0:int,1:int}>
     */
    public function resultatsPossibles(): array
    {
        $victoires = [];

        if ($this->type === self::MANCHES_SECHES) {
            $minimum = intdiv($this->nbManches, 2) + 1;

            for ($g = $this->nbManches; $g >= $minimum; $g--) {
                $victoires[] = [$g, $this->nbManches - $g];
            }
        } elseif ($this->type === self::MANCHE_UNIQUE || $this->type === self::AU_TEMPS) {
            $victoires[] = [1, 0];
        } else {
            for ($p = 0; $p < $this->nbManches; $p++) {
                $victoires[] = [$this->nbManches, $p];
            }
        }

        $defaites = array_reverse(
            array_map(static fn (array $r): array => [$r[1], $r[0]], $victoires)
        );

        $tous = array_merge($victoires, $defaites);

        // En sec pair, l'egalite parfaite est un resultat admissible.
        if (!$this->victoiresExploitables()) {
            $moitie = intdiv($this->nbManches, 2);
            array_splice($tous, count($victoires), 0, [[$moitie, $moitie]]);
        }

        return $tous;
    }

    /**
     * Duree estimee d'une partie, en minutes (RG-91).
     *
     * Base empirique : une manche a 11 points dure environ six minutes
     * plateau compris ; le temps croit avec le nombre de points.
     */
    public function dureeEstimee(): int
    {
        if ($this->type === self::AU_TEMPS) {
            return (int) $this->duree + 2;
        }

        $parManche = max(3, (int) round($this->pointsParManche * 0.55));
        $manches   = match ($this->type) {
            self::MANCHES_GAGNANTES => $this->nbManches + intdiv($this->nbManches, 2),
            self::MANCHES_SECHES    => $this->nbManches,
            default                 => 1,
        };

        return max(5, $parManche * $manches);
    }

    public function libelle(): string
    {
        $base = match ($this->type) {
            self::MANCHES_GAGNANTES => sprintf('%d manches gagnantes', $this->nbManches),
            self::MANCHES_SECHES    => sprintf('%d manches seches', $this->nbManches),
            self::MANCHE_UNIQUE     => 'manche unique',
            self::AU_TEMPS          => sprintf('au temps (%d min)', (int) $this->duree),
            self::SCORE_CIBLE       => 'score cible',
            default                 => $this->type,
        };

        return $this->pointsParManche === 11
            ? $base
            : sprintf('%s a %d points', $base, $this->pointsParManche);
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'type_format'       => $this->type,
            'nb_manches'        => $this->nbManches,
            'points_par_manche' => $this->pointsParManche,
            'deux_points_ecart' => $this->deuxPointsEcart,
            'plafond_manche'    => $this->plafondManche,
            'duree'             => $this->duree,
            'acceleration'      => $this->acceleration,
        ];
    }
}

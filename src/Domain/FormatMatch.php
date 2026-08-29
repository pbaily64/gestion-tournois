<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

/**
 * Format de jeu retenu pour une phase.
 *
 * L'organisateur le decide au debut de chaque soiree, en fonction du
 * nombre de presents et du volume de matchs a disputer (dossier de
 * conception, section 6.2). Il determine le nombre de cases de score a
 * saisir et a imprimer, et les resultats admissibles.
 *
 * Il a aussi une consequence sur le classement general apres les poules
 * (section 4.5) : en trois sets secs, chaque match compte exactement
 * trois sets, donc les sets gagnes et la difference de sets sont
 * comparables d'un joueur a l'autre. En sets gagnants, ils ne le sont
 * plus, et d'autres criteres doivent prendre le relais.
 */
enum FormatMatch: string
{
    /** Tous les sets sont joues. Resultats : 3-0, 2-1, 1-2, 0-3. */
    case TroisSetsSecs = '3_sets_secs';

    /** Le premier a deux sets l'emporte. Trois sets au maximum. */
    case DeuxSetsGagnants = '2_sets_gagnants';

    /** Le premier a trois sets l'emporte. Cinq sets au maximum. */
    case TroisSetsGagnants = '3_sets_gagnants';

    public function libelle(): string
    {
        return match ($this) {
            self::TroisSetsSecs     => 'Trois sets secs',
            self::DeuxSetsGagnants  => 'Deux sets gagnants',
            self::TroisSetsGagnants => 'Trois sets gagnants',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TroisSetsSecs     => 'Les trois sets sont toujours joues : 3-0, 2-1, 1-2 ou 0-3.',
            self::DeuxSetsGagnants  => 'Le premier a deux sets l\'emporte, en trois sets au maximum.',
            self::TroisSetsGagnants => 'Le premier a trois sets l\'emporte, en cinq sets au maximum.',
        };
    }

    /** Nombre de cases de score a afficher et a imprimer. */
    public function nombreDeCases(): int
    {
        return match ($this) {
            self::TroisSetsSecs, self::DeuxSetsGagnants => 3,
            self::TroisSetsGagnants                     => 5,
        };
    }

    /** Sets necessaires pour gagner, ou null si tous les sets sont joues. */
    public function setsPourGagner(): ?int
    {
        return match ($this) {
            self::TroisSetsSecs     => null,
            self::DeuxSetsGagnants  => 2,
            self::TroisSetsGagnants => 3,
        };
    }

    /**
     * Les sets gagnes et perdus sont-ils comparables entre joueurs ?
     *
     * Vrai en trois sets secs seulement : chaque match y compte le meme
     * nombre de sets. Ailleurs, un joueur qui gagne peniblement 3-2
     * accumule plus de sets qu'un joueur qui ecrase 3-0.
     */
    public function setsComparables(): bool
    {
        return $this === self::TroisSetsSecs;
    }

    /**
     * Verifie un resultat exprime en sets.
     *
     * @return string|null message d'erreur, ou null si le score est valide
     */
    public function verifier(int $sets1, int $sets2): ?string
    {
        if ($sets1 < 0 || $sets2 < 0) {
            return 'Un nombre de sets ne peut pas etre negatif.';
        }

        if ($sets1 === $sets2) {
            return 'Un match ne peut pas se terminer sur une egalite.';
        }

        $total   = $sets1 + $sets2;
        $gagnant = max($sets1, $sets2);
        $perdant = min($sets1, $sets2);

        if ($this === self::TroisSetsSecs) {
            return $total === 3
                ? null
                : 'En trois sets secs, les trois sets se jouent : 3-0, 2-1, 1-2 ou 0-3.';
        }

        $requis = $this->setsPourGagner();

        if ($gagnant !== $requis) {
            return sprintf('Le vainqueur doit remporter exactement %d sets.', $requis);
        }

        if ($perdant >= $requis) {
            return 'Le perdant ne peut pas atteindre le nombre de sets gagnants.';
        }

        return null;
    }

    /**
     * Tous les resultats possibles, du plus net au plus serre puis en
     * miroir. Sert a proposer des boutons de saisie rapide.
     *
     * @return list<array{0:int,1:int}>
     */
    public function resultatsPossibles(): array
    {
        $victoires = match ($this) {
            self::TroisSetsSecs     => [[3, 0], [2, 1]],
            self::DeuxSetsGagnants  => [[2, 0], [2, 1]],
            self::TroisSetsGagnants => [[3, 0], [3, 1], [3, 2]],
        };

        $defaites = array_reverse(
            array_map(static fn (array $r): array => [$r[1], $r[0]], $victoires)
        );

        return array_merge($victoires, $defaites);
    }

    /** @return list<self> */
    public static function tous(): array
    {
        return self::cases();
    }
}

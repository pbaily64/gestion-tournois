<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

use InvalidArgumentException;
use RMCF\Tournois\Formule\FormatPartie;

/**
 * Une cascade de departage : la liste ordonnee des criteres, chacun
 * avec sa portee, plus deux interrupteurs (retrait iteratif, interdire
 * les ex aequo).
 *
 * C'est la seule chose qui distingue le reglement ITTF du reglement
 * FFTT, du reglement FRBTT et de la regle du Mickey By Night. Il
 * n'existe donc pas de code « departage FFTT » : il existe une donnee
 * (RG-50).
 *
 * ECRITURE COURTE
 *
 * Une cascade se declare par des codes, avec la portee derriere une
 * arobase quand elle differe de la portee naturelle du critere :
 *
 *     Cascade::depuisCodes([
 *         'points_rencontre',
 *         'points_rencontre@entre_ex_aequo',
 *         'quotient_manches@entre_ex_aequo',
 *     ]);
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class Cascade
{
    /** @param list<array{critere:Critere, portee:Portee}> $etapes */
    public function __construct(
        public readonly array $etapes,
        public readonly bool $retraitIteratif = true,
        public readonly bool $interdireExAequo = true,
        public readonly array $backstop = [Critere::ClassementOfficiel, Critere::Alphabetique],
        public readonly string $libelle = '',
    ) {
        if ($etapes === []) {
            throw new InvalidArgumentException('Une cascade compte au moins un critere.');
        }
    }

    /**
     * @param list<string> $codes
     */
    public static function depuisCodes(
        array $codes,
        bool $retraitIteratif = true,
        bool $interdireExAequo = true,
        string $libelle = '',
    ): self {
        $etapes = [];

        foreach ($codes as $code) {
            [$nom, $portee] = array_pad(explode('@', $code, 2), 2, null);

            $critere = Critere::tryFrom((string) $nom);

            if ($critere === null) {
                throw new InvalidArgumentException(sprintf('Critere inconnu : %s.', $nom));
            }

            $etapes[] = [
                'critere' => $critere,
                'portee'  => $portee === null
                    ? $critere->porteeParDefaut()
                    : (Portee::tryFrom($portee) ?? $critere->porteeParDefaut()),
            ];
        }

        return new self($etapes, $retraitIteratif, $interdireExAequo, libelle: $libelle);
    }

    /**
     * Les etapes effectivement appliquees, backstop compris.
     *
     * RG-51 — si les ex aequo sont interdits, la cascade doit se
     * terminer par un critere total. Plutot que de refuser une
     * configuration incomplete au moment du calcul, on complete ici et
     * le validateur signale l'omission a l'ouverture du tournoi.
     *
     * @return list<array{critere:Critere, portee:Portee}>
     */
    public function etapesEffectives(): array
    {
        $etapes = $this->etapes;

        if (!$this->interdireExAequo) {
            return $etapes;
        }

        $derniere = $etapes[count($etapes) - 1]['critere'];

        if ($derniere->total()) {
            return $etapes;
        }

        foreach ($this->backstop as $critere) {
            if (!$this->contient($critere)) {
                $etapes[] = ['critere' => $critere, 'portee' => Portee::ToutePoule];
            }
        }

        return $etapes;
    }

    /**
     * Adapte la cascade au format de jeu (RG-52).
     *
     * En manches seches en nombre pair, le 2-2 existe : le critere
     * « victoires » n'a plus de sens et il est retire. Le validateur
     * previent l'organisateur ; le moteur, lui, ne doit surtout pas
     * appliquer silencieusement un critere inadapte.
     */
    public function adapteeAuFormat(FormatPartie $format): self
    {
        if ($format->victoiresExploitables()) {
            return $this;
        }

        $etapes = array_values(array_filter(
            $this->etapes,
            static fn (array $e): bool => $e['critere'] !== Critere::Victoires
        ));

        if ($etapes === $this->etapes) {
            return $this;
        }

        if ($etapes === []) {
            $etapes = [['critere' => Critere::PointsRencontre, 'portee' => Portee::ToutePoule]];
        }

        return new self(
            $etapes,
            $this->retraitIteratif,
            $this->interdireExAequo,
            $this->backstop,
            $this->libelle
        );
    }

    public function contient(Critere $critere): bool
    {
        foreach ($this->etapes as $etape) {
            if ($etape['critere'] === $critere) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Critere> */
    public function criteres(): array
    {
        return array_map(
            static fn (array $e): Critere => $e['critere'],
            $this->etapes
        );
    }

    /** La cascade se termine-t-elle sur un critere total ? (RG-51) */
    public function seTermineSurUnCritereTotal(): bool
    {
        $derniere = $this->etapes[count($this->etapes) - 1]['critere'];

        return $derniere->total();
    }

    /** La cascade mobilise-t-elle des manches ou des points ? (RG-54) */
    public function utiliseLesManches(): bool
    {
        foreach ($this->etapes as $etape) {
            if ($etape['critere']->fondeSurLesManches()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{critere:string,portee:string}> */
    public function enTableau(): array
    {
        return array_map(
            static fn (array $e): array => [
                'critere' => $e['critere']->value,
                'portee'  => $e['portee']->value,
            ],
            $this->etapes
        );
    }

    /** Description en francais, pour le panneau de resume. */
    public function description(): string
    {
        $morceaux = [];

        foreach ($this->etapes as $i => $etape) {
            $morceaux[] = sprintf(
                '%d. %s (%s)',
                $i + 1,
                $etape['critere']->libelle(),
                $etape['portee']->libelle()
            );
        }

        return implode(' — ', $morceaux);
    }
}

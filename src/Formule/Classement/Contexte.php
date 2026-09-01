<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

use RMCF\Tournois\Formule\FormatPartie;

/**
 * Tout ce que le moteur de classement doit savoir en plus des parties.
 *
 * Les criteres administratifs — classement officiel, age, ordre
 * alphabetique — ne se lisent pas dans les resultats : ils viennent de
 * l'inscription. Les criteres de la section 7.6 ont besoin du FORMAT de
 * la phase et de l'egalite ou non des tailles de poules pour se
 * resoudre (RG-53). Le tout est rassemble ici, pour que le moteur
 * n'aille rien chercher lui-meme.
 *
 * ORDRE ALPHABETIQUE ET TIRAGE AU SORT
 *
 * Ces deux criteres doivent produire un ordre TOTAL et STABLE : si le
 * classement est recalcule, il doit rendre exactement le meme resultat.
 * L'ordre alphabetique est donc calcule une fois, sur des noms replies
 * en ASCII majuscule pour que « Écheveau » se range bien avec les E. Le
 * tirage au sort derive d'une graine stockee avec le tournoi : il est
 * aleatoire une fois, jamais deux.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class Contexte
{
    /** @var array<string,int> nom replie => position */
    private array $ordreAlphabetique;

    /**
     * @param array<string, array{
     *     nom?:string, rang_officiel?:int, age?:int, place_poule?:int|null,
     *     points_bareme?:float, groupe?:string
     * }> $attributs
     */
    public function __construct(
        private readonly array $attributs,
        public readonly FormatPartie $format = new FormatPartie(),
        public readonly bool $groupesTaillesEgales = true,
        public readonly int $graine = 0,
    ) {
        $noms = [];

        foreach (array_keys($attributs) as $entite) {
            $noms[(string) $entite] = self::replier(
                (string) ($attributs[$entite]['nom'] ?? $entite)
            );
        }

        asort($noms);

        $this->ordreAlphabetique = [];
        $position                = 0;

        foreach (array_keys($noms) as $entite) {
            $this->ordreAlphabetique[$entite] = $position++;
        }
    }

    /**
     * Contexte minimal : les entites n'ont que leur identifiant.
     *
     * @param list<string> $entites
     */
    public static function simple(array $entites, ?FormatPartie $format = null): self
    {
        $attributs = [];

        foreach ($entites as $entite) {
            $attributs[$entite] = ['nom' => $entite];
        }

        return new self($attributs, $format ?? new FormatPartie());
    }

    public function nom(string $entite): string
    {
        return (string) ($this->attributs[$entite]['nom'] ?? $entite);
    }

    /**
     * Rang au classement officiel, du plus faible au plus fort.
     *
     * Convention AFTT : NC vaut 0, A vaut 17. Le critere se lit donc en
     * decroissant, le mieux classe devant.
     */
    public function rangOfficiel(string $entite): int
    {
        return (int) ($this->attributs[$entite]['rang_officiel'] ?? 0);
    }

    public function age(string $entite): int
    {
        return (int) ($this->attributs[$entite]['age'] ?? 0);
    }

    /**
     * Place obtenue en poule, ou null si elle n'est pas calculee.
     *
     * Une place absente ne doit pas remonter en tete du classement :
     * elle vaut une valeur haute, qui la renvoie en queue.
     */
    public function placePoule(string $entite): int
    {
        return (int) ($this->attributs[$entite]['place_poule'] ?? 9999);
    }

    public function pointsBareme(string $entite): float
    {
        return (float) ($this->attributs[$entite]['points_bareme'] ?? 0.0);
    }

    public function groupe(string $entite): ?string
    {
        $groupe = $this->attributs[$entite]['groupe'] ?? null;

        return $groupe === null ? null : (string) $groupe;
    }

    public function rangAlphabetique(string $entite): int
    {
        return $this->ordreAlphabetique[$entite] ?? PHP_INT_MAX;
    }

    /**
     * Position de tirage au sort, stable pour une graine donnee.
     *
     * Le meme tournoi rejoue mille fois rend mille fois le meme ordre :
     * c'est la condition pour qu'un classement soit reproductible.
     */
    public function rangTirage(string $entite): int
    {
        return (int) (crc32($this->graine . '|' . $entite) % 1000003);
    }

    /** @return list<string> */
    public function entites(): array
    {
        return array_map('strval', array_keys($this->attributs));
    }

    /** Replie un nom en ASCII majuscule, pour un tri stable. */
    private static function replier(string $nom): string
    {
        $translitere = strtr(
            $nom,
            [
                'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
                'Ç' => 'C', 'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
                'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ñ' => 'N',
                'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
                'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ý' => 'Y',
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
                'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
                'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
                'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
                'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            ]
        );

        return strtoupper($translitere);
    }
}

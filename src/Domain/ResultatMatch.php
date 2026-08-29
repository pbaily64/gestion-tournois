<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

/**
 * Resultat d'un match, deduit des points de chaque set.
 *
 * L'organisateur encode les points set par set — 11-7, 9-11, 11-5 — et
 * le nombre de sets gagnes comme le vainqueur en decoulent. Rien n'est
 * saisi deux fois, donc rien ne peut se contredire.
 *
 * LE HANDICAP EST DEJA DANS LES POINTS
 * Le score encode est celui du marquoir, avance de handicap comprise.
 * Un joueur qui demarre a 6 et l'emporte 11-8 a marque 5 points reels ;
 * seul le 11-8 est enregistre. C'est ce qui figure sur la feuille de
 * match, et c'est ce qui doit rester consultable.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class ResultatMatch
{
    /** Points a atteindre pour emporter un set. */
    public const POINTS_PAR_SET = 11;

    /**
     * @param list<array{0:int,1:int}> $manches points de chaque set joue
     */
    private function __construct(
        public readonly int $sets1,
        public readonly int $sets2,
        public readonly array $manches,
    ) {
    }

    /** Le joueur 1 a-t-il gagne ? */
    public function vainqueurEstLePremier(): bool
    {
        return $this->sets1 > $this->sets2;
    }

    /** '1' ou '2', tel que stocke sur la rencontre. */
    public function vainqueur(): string
    {
        return $this->vainqueurEstLePremier() ? '1' : '2';
    }

    /**
     * Construit le resultat a partir des cases de saisie.
     *
     * Une case laissee vide vaut null ou la chaine vide — un formulaire
     * HTML renvoie la seconde. Un set dont les deux cases sont vides n'a
     * pas ete joue ; les sets non joues doivent tous se situer apres les
     * sets joues.
     *
     * @param  list<array{0:int|string|null,1:int|string|null}> $cases
     * @throws ResultatInvalide
     */
    public static function depuisCases(array $cases, FormatMatch $format): self
    {
        $manches  = [];
        $termine  = false;

        foreach (array_values($cases) as $i => [$p1, $p2]) {
            $vide = ($p1 === null || $p1 === '') && ($p2 === null || $p2 === '');

            if ($vide) {
                $termine = true;
                continue;
            }

            if ($termine) {
                throw new ResultatInvalide(sprintf(
                    'Le set %d est renseigne alors qu\'un set precedent est vide.',
                    $i + 1
                ));
            }

            if ($p1 === null || $p2 === null) {
                throw new ResultatInvalide(sprintf(
                    'Le set %d n\'a qu\'un seul score.',
                    $i + 1
                ));
            }

            $erreur = self::verifierManche((int) $p1, (int) $p2);

            if ($erreur !== null) {
                throw new ResultatInvalide(sprintf('Set %d : %s', $i + 1, $erreur));
            }

            $manches[] = [(int) $p1, (int) $p2];
        }

        if ($manches === []) {
            throw new ResultatInvalide('Aucun set n\'est renseigne.');
        }

        $sets1 = 0;
        $sets2 = 0;

        foreach ($manches as [$p1, $p2]) {
            $p1 > $p2 ? $sets1++ : $sets2++;
        }

        $erreur = $format->verifier($sets1, $sets2);

        if ($erreur !== null) {
            throw new ResultatInvalide($erreur);
        }

        self::verifierArret($manches, $sets1, $sets2, $format);

        return new self($sets1, $sets2, $manches);
    }

    /**
     * Verifie un set isole.
     *
     * Regle du tennis de table : 11 points, avec deux points d'ecart.
     * Au-dela de 11, l'ecart est donc exactement de deux.
     *
     * @return string|null message d'erreur, ou null si le set est valide
     */
    public static function verifierManche(int $p1, int $p2): ?string
    {
        if ($p1 < 0 || $p2 < 0) {
            return 'un score ne peut pas etre negatif.';
        }

        $gagnant = max($p1, $p2);
        $perdant = min($p1, $p2);

        if ($gagnant < self::POINTS_PAR_SET) {
            return sprintf('le set doit aller au moins a %d points.', self::POINTS_PAR_SET);
        }

        if ($gagnant === self::POINTS_PAR_SET) {
            return $perdant <= self::POINTS_PAR_SET - 2
                ? null
                : 'a 10 partout, il faut deux points d\'ecart.';
        }

        return $perdant === $gagnant - 2
            ? null
            : 'au-dela de 11 points, l\'ecart doit etre exactement de deux.';
    }

    /**
     * Le match doit s'arreter des qu'il est joue : en sets gagnants, on
     * ne dispute pas de set apres la victoire.
     *
     * @param list<array{0:int,1:int}> $manches
     */
    private static function verifierArret(array $manches, int $sets1, int $sets2, FormatMatch $format): void
    {
        $requis = $format->setsPourGagner();

        if ($requis === null) {
            return; // trois sets secs : les trois se jouent toujours
        }

        $courant1 = 0;
        $courant2 = 0;

        foreach ($manches as $i => [$p1, $p2]) {
            if ($courant1 === $requis || $courant2 === $requis) {
                throw new ResultatInvalide(sprintf(
                    'Le set %d n\'aurait pas du etre joue : le match etait deja gagne.',
                    $i + 1
                ));
            }

            $p1 > $p2 ? $courant1++ : $courant2++;
        }
    }
}

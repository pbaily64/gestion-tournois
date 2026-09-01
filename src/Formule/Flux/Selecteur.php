<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Flux;

/**
 * Le selecteur d'un flux : QUI passe d'une phase a la suivante (C.5).
 *
 * Cette enumeration est le coeur de la factorisation annoncee au §9.5.
 * Consolante, barrage, tableau des perdants, vies multiples, montees et
 * descentes de criterium ne sont pas cinq mecanismes : ce sont cinq
 * valeurs de ce type. Il n'y a pas quatre topologies a coder, il y en a
 * une, et une table de flux.
 *
 * Elle doit rester EXHAUSTIVE : toute formule qu'on ne saurait pas
 * exprimer ici exigerait du code specifique, ce que la matrice de
 * couverture C.12 interdit.
 */
enum Selecteur: string
{
    /** Les k-iemes de chaque groupe (parametre = k). */
    case PlaceExacte = 'place_exacte';

    /** Les places k1 a k2 de chaque groupe (parametre = "k1-k2"). */
    case PlacesDeA = 'places_de_a';

    /** Les n meilleurs n-iemes, toutes poules confondues. */
    case MeilleursNiemes = 'meilleurs_n_iemes';

    /** Tous ceux qu'aucun autre flux n'a pris (evalue en dernier, RG-32). */
    case NonQualifies = 'non_qualifies';

    /** Les perdants du tour k du tableau source — l'alimentation d'une consolante. */
    case PerdantsTour = 'perdants_tour';

    /** Les vainqueurs du tour k. */
    case VainqueursTour = 'vainqueurs_tour';

    /** Routage des vies : ceux qui ont atteint n defaites (§3.4). */
    case EliminesAvecNDefaites = 'elimines_avec_n_defaites';

    /** Les n premiers du classement inter-groupes de la phase source. */
    case TopNGlobal = 'top_n_global';

    /** Criterium : les premiers de chaque poule montent d'une division. */
    case Montants = 'montants';

    /** Criterium : les derniers descendent. */
    case Descendants = 'descendants';

    /** Comblement de places vacantes, selon `regle_ordre`. */
    case Repeches = 'repeches';

    /** Toute la phase source. */
    case Tous = 'tous';

    /** L'organisateur designe nommement. */
    case Manuel = 'manuel';

    public function libelle(): string
    {
        return match ($this) {
            self::PlaceExacte           => 'Les k-ièmes de chaque groupe',
            self::PlacesDeA             => 'Les places k1 à k2 de chaque groupe',
            self::MeilleursNiemes       => 'Les meilleurs n-ièmes toutes poules confondues',
            self::NonQualifies          => 'Tous les non-qualifiés',
            self::PerdantsTour          => 'Les perdants du tour k',
            self::VainqueursTour        => 'Les vainqueurs du tour k',
            self::EliminesAvecNDefaites => 'Les éliminés ayant n défaites',
            self::TopNGlobal            => 'Les n premiers du classement général',
            self::Montants              => 'Les montants',
            self::Descendants           => 'Les descendants',
            self::Repeches              => 'Les repêchés',
            self::Tous                  => 'Toute la phase source',
            self::Manuel                => 'Désignation manuelle',
        };
    }

    /**
     * Le selecteur exige-t-il que la phase source soit close ?
     *
     * `perdants_tour` et `vainqueurs_tour` n'ont besoin que du tour
     * concerne : c'est ce qui permet a une consolante de demarrer alors
     * que le tableau principal n'en est qu'aux quarts. Les selecteurs
     * fondes sur un classement, eux, exigent la cloture complete.
     */
    public function exigeCloture(): bool
    {
        return ! in_array($this, [self::PerdantsTour, self::VainqueursTour, self::Manuel], true);
    }

    /** Le selecteur s'applique-t-il groupe par groupe, ou globalement ? */
    public function parGroupe(): bool
    {
        return in_array(
            $this,
            [self::PlaceExacte, self::PlacesDeA, self::Montants, self::Descendants],
            true
        );
    }

    /** Le parametre attendu est-il obligatoire ? */
    public function exigeParametre(): bool
    {
        return in_array(
            $this,
            [
                self::PlaceExacte,
                self::PlacesDeA,
                self::MeilleursNiemes,
                self::PerdantsTour,
                self::VainqueursTour,
                self::EliminesAvecNDefaites,
                self::TopNGlobal,
            ],
            true
        );
    }
}

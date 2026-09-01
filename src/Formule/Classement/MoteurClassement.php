<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Classement;

use RMCF\Tournois\Formule\FormatPartie;

/**
 * Le moteur de departage — coeur de la section 7.
 *
 * Toute la variete des reglements de classement se ramene a un seul
 * algorithme, parametre par trois choses (section 7.8) :
 *
 *   1. la liste ordonnee des criteres ;
 *   2. pour chaque critere, sa portee (toute la poule / entre ex aequo) ;
 *   3. l'activation du retrait iteratif.
 *
 * Il n'existe donc PAS de code « departage FFTT » ni « departage
 * FRBTT » : il existe des cascades, qui sont des donnees (RG-50).
 *
 * L'ALGORITHME
 *
 *   on groupe les entites par la valeur du premier critere ;
 *   un groupe reduit a une entite est classe, et l'on retient le
 *   critere qui l'a isolee ;
 *   un groupe de plusieurs entites est repris :
 *     - avec retrait iteratif, on recommence la cascade depuis le
 *       debut sur le seul sous-groupe (les criteres « entre ex aequo »
 *       se restreignent alors d'eux-memes a leurs confrontations) ;
 *     - sans retrait iteratif, on passe simplement au critere suivant.
 *
 * La terminaison est garantie : un sous-groupe est toujours
 * strictement plus petit que le groupe dont il est issu.
 *
 * DEUX CAS PARTICULIERS
 *
 * La confrontation directe ne s'applique qu'a DEUX entites a egalite.
 * A trois et plus, un sous-championnat de trois joueurs peut etre
 * cyclique et ne rien trancher : le critere est alors ignore, comme le
 * prevoit le reglement FFTT.
 *
 * Le barrage n'est pas calculable : c'est un departage sportif. Le
 * moteur s'arrete, marque les entites concernees et laisse
 * l'organisateur programmer le match.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class MoteurClassement
{
    /** @var list<array{critere:Critere, portee:Portee}> */
    private array $etapes;

    /** @var array<string,Critere> entite => critere qui l'a departagee */
    private array $trace = [];

    /** @var array<string,array<string,float>> entite => critere => valeur */
    private array $valeurs = [];

    /** @var array<string,bool> entites en attente d'un barrage */
    private array $barrages = [];

    /** @var array<string,float>|null cache du Buchholz */
    private ?array $buchholz = null;

    /**
     * @param list<array<string,mixed>> $partiesPoule
     */
    private function __construct(
        private readonly array $partiesPoule,
        private readonly Cascade $cascade,
        private readonly Contexte $contexte,
        private readonly BaremeRencontre $bareme,
    ) {
        $this->etapes = $cascade
            ->adapteeAuFormat($contexte->format)
            ->etapesEffectives();
    }

    /**
     * Classe un ensemble d'entites.
     *
     * @param  list<string>              $entites
     * @param  list<array<string,mixed>> $parties
     * @return list<Rang>
     */
    public static function classer(
        array $entites,
        array $parties,
        Cascade $cascade,
        Contexte $contexte,
        ?BaremeRencontre $bareme = null,
    ): array {
        $moteur = new self($parties, $cascade, $contexte, $bareme ?? new BaremeRencontre());

        return $moteur->executer(array_values($entites));
    }

    /**
     * Classe les entites d'une phase a plusieurs groupes, en rendant le
     * classement de chaque groupe.
     *
     * @param  array<string,list<string>> $groupes    libelle => entites
     * @param  list<array<string,mixed>>  $parties    toutes phases confondues
     * @return array<string,list<Rang>>
     */
    public static function classerParGroupe(
        array $groupes,
        array $parties,
        Cascade $cascade,
        Contexte $contexte,
        ?BaremeRencontre $bareme = null,
    ): array {
        $classements = [];

        foreach ($groupes as $libelle => $entites) {
            $classements[$libelle] = self::classer(
                $entites,
                Statistiques::restreindre($parties, $entites),
                $cascade,
                $contexte,
                $bareme
            );
        }

        return $classements;
    }

    /**
     * @param  list<string> $entites
     * @return list<Rang>
     */
    private function executer(array $entites): array
    {
        $bilansPoule = Statistiques::calculer($entites, $this->partiesPoule, $this->bareme);
        $blocs       = $this->resoudre($entites, 0);

        $rangs = [];
        $place = 1;

        foreach ($blocs as $bloc) {
            $exAequo = count($bloc) > 1;

            foreach ($bloc as $entite) {
                $rangs[] = new Rang(
                    entite: $entite,
                    rang: $place,
                    critereDecisif: $this->trace[$entite] ?? null,
                    exAequo: $exAequo,
                    barrageRequis: $this->barrages[$entite] ?? false,
                    valeurs: $this->valeurs[$entite] ?? [],
                    bilan: $bilansPoule[$entite]->enTableau(),
                );
                $place++;
            }
        }

        return $rangs;
    }

    /**
     * Departage un groupe a partir du critere d'indice $depuis.
     *
     * @param  list<string>       $groupe
     * @return list<list<string>> blocs ordonnes, du premier au dernier
     */
    private function resoudre(array $groupe, int $depuis): array
    {
        if (count($groupe) <= 1) {
            return [$groupe];
        }

        for ($i = $depuis; $i < count($this->etapes); $i++) {
            $critere = $this->resoudreAuto($this->etapes[$i]['critere']);
            $portee  = $this->etapes[$i]['portee'];

            if (!$critere->calculable()) {
                foreach ($groupe as $entite) {
                    $this->barrages[$entite] = true;
                }

                return [$groupe];
            }

            if ($critere === Critere::ConfrontationDirecte && count($groupe) !== 2) {
                continue; // ne s'applique qu'a deux ex aequo
            }

            $parties = $portee === Portee::EntreExAequo
                ? Statistiques::restreindre($this->partiesPoule, $groupe)
                : $this->partiesPoule;

            $bilans  = Statistiques::calculer($groupe, $parties, $this->bareme);
            $paquets = $this->grouper($groupe, $critere, $bilans);

            if (count($paquets) === 1) {
                continue; // le critere ne departage rien, on passe au suivant
            }

            $sortie = [];

            foreach ($paquets as $paquet) {
                if (count($paquet) === 1) {
                    $this->trace[$paquet[0]] = $critere;
                    $sortie[]                = $paquet;

                    continue;
                }

                $sousBlocs = $this->cascade->retraitIteratif
                    ? $this->resoudre($paquet, 0)
                    : $this->resoudre($paquet, $i + 1);

                foreach ($sousBlocs as $sousBloc) {
                    $sortie[] = $sousBloc;
                }
            }

            return $sortie;
        }

        return [$groupe]; // aucun critere ne separe : egalite irreductible
    }

    /**
     * Regroupe les entites par valeur d'un critere, de la meilleure a
     * la moins bonne.
     *
     * @param  list<string>        $groupe
     * @param  array<string,Bilan> $bilans
     * @return list<list<string>>
     */
    private function grouper(array $groupe, Critere $critere, array $bilans): array
    {
        $paquets = [];

        foreach ($groupe as $entite) {
            $valeur = $this->valeur($critere, $entite, $bilans);

            $this->valeurs[$entite][$critere->value] = $valeur;

            $clef = number_format($valeur, 6, '.', '');

            $paquets[$clef]['valeur']    = $valeur;
            $paquets[$clef]['entites'][] = $entite;
        }

        uasort(
            $paquets,
            static fn (array $x, array $y): int => $x['valeur'] <=> $y['valeur']
        );

        if (!$critere->croissant()) {
            $paquets = array_reverse($paquets, true);
        }

        return array_values(array_map(
            static fn (array $p): array => $p['entites'],
            $paquets
        ));
    }

    /**
     * Valeur d'un critere pour une entite.
     *
     * @param array<string,Bilan> $bilans bilans calcules sur la portee
     */
    private function valeur(Critere $critere, string $entite, array $bilans): float
    {
        $bilan = $bilans[$entite] ?? new Bilan();

        return match ($critere) {
            Critere::PointsRencontre       => (float) $bilan->pointsRencontre,
            Critere::Victoires,
            Critere::ConfrontationDirecte  => (float) $bilan->victoires,
            Critere::QuotientVictoires     => $bilan->quotientVictoires(),
            Critere::QuotientManches       => $bilan->quotientManches(),
            Critere::DiffManches           => (float) $bilan->diffManches(),
            Critere::ManchesGagnees        => (float) $bilan->manchesPour,
            Critere::RatioManches          => $bilan->ratioManches(),
            Critere::DiffManchesNormalisee => $bilan->diffManchesNormalisee(),
            Critere::QuotientPoints        => $bilan->quotientPoints(),
            Critere::DiffPoints            => (float) $bilan->diffPoints(),
            Critere::RatioPoints           => $bilan->ratioPoints(),
            Critere::NbParties             => (float) $bilan->parties,
            Critere::PlacePoule            => (float) $this->contexte->placePoule($entite),
            Critere::PointsBareme          => $this->contexte->pointsBareme($entite),
            Critere::Buchholz              => $this->valeurBuchholz($entite),
            Critere::ClassementOfficiel    => (float) $this->contexte->rangOfficiel($entite),
            Critere::Age                   => (float) $this->contexte->age($entite),
            Critere::Alphabetique          => (float) $this->contexte->rangAlphabetique($entite),
            Critere::TirageAuSort          => (float) $this->contexte->rangTirage($entite),
            Critere::DepartageManchesAuto,
            Critere::Barrage               => 0.0, // resolus ou interceptes en amont
        };
    }

    private function valeurBuchholz(string $entite): float
    {
        if ($this->buchholz === null) {
            $entites = [];

            foreach ($this->partiesPoule as $partie) {
                $entites[(string) $partie['a']] = true;
                $entites[(string) $partie['b']] = true;
            }

            $this->buchholz = Statistiques::buchholz(Statistiques::calculer(
                array_keys($entites),
                $this->partiesPoule,
                $this->bareme
            ));
        }

        return $this->buchholz[$entite] ?? 0.0;
    }

    /**
     * Resout le critere « departage sur les manches, selon le format ».
     *
     * RG-53 — c'est la reponse au point ouvert de la section 7.6, et la
     * raison pour laquelle la cascade ne peut pas etre une simple liste
     * figee : le critere pertinent depend du format de la phase.
     *
     *   sec + poules de meme taille  -> difference de manches
     *   sec + poules inegales        -> difference normalisee
     *   manches gagnantes            -> ratio manches gagnees / jouees
     */
    private function resoudreAuto(Critere $critere): Critere
    {
        if ($critere !== Critere::DepartageManchesAuto) {
            return $critere;
        }

        return self::critereDeManches($this->contexte->format, $this->contexte->groupesTaillesEgales);
    }

    /** Expose la resolution du RG-53, pour l'affichage et les tests. */
    public static function critereDeManches(FormatPartie $format, bool $groupesTaillesEgales): Critere
    {
        if (!$format->manchesComparables()) {
            return Critere::RatioManches;
        }

        return $groupesTaillesEgales
            ? Critere::DiffManches
            : Critere::DiffManchesNormalisee;
    }
}

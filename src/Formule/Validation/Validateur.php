<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Validation;

use RMCF\Tournois\Formule\Catalogue;
use RMCF\Tournois\Formule\Classement\Cascade;
use RMCF\Tournois\Formule\Classement\Critere;
use RMCF\Tournois\Formule\Deroulement\DefinitionPhase;
use RMCF\Tournois\Formule\Deroulement\DefinitionTournoi;
use RMCF\Tournois\Formule\Deroulement\MoteurTournoi;
use RMCF\Tournois\Formule\Expression;
use RMCF\Tournois\Formule\Flux\Flux;
use RMCF\Tournois\Formule\Flux\ResultatsEnMemoire;
use RMCF\Tournois\Formule\FormatPartie;
use RMCF\Tournois\Formule\Generation\Generateurs;
use RMCF\Tournois\Formule\Parametres;

/**
 * Le controle avant ouverture (§10.3).
 *
 * Le validateur repond a une question et une seule : « ce tournoi
 * peut-il se derouler jusqu'au bout et produire un classement
 * incontestable ? » Il s'execute AVANT que quiconque ait joue, parce
 * qu'apres il est trop tard : on ne change pas la regle de departage
 * d'une poule dont les matchs sont deja saisis.
 *
 * Il regroupe les controles que l'annexe C dissemine dans dix blocs.
 * Les trois qui rattrapent le plus d'erreurs reelles :
 *
 *   RG-51  une cascade qui ne se termine pas sur un critere total ne
 *          peut pas honorer « pas d'ex aequo ». C'est le piege le plus
 *          frequent, parce que la cascade parait complete jusqu'au soir
 *          ou deux joueurs sont rigoureusement egaux.
 *
 *   RG-12  un quotient de manches sur toute la poule est faux si la
 *          rencontre s'arrete a l'acquis : les parties non disputees
 *          manquent au denominateur.
 *
 *   RG-77  un handicap de 8 points sur des manches a 11 rend la manche
 *          decidee d'avance. Le couplage handicap / format n'est pas
 *          cosmetique (§6.3).
 */
final class Validateur
{
    /**
     * @param  int|null $nbInscrits si connu, active les controles de volume
     * @return list<Anomalie>
     */
    public function valider(DefinitionTournoi $definition, ?int $nbInscrits = null): array
    {
        $anomalies = [];

        $anomalies = [...$anomalies, ...$this->validerParametres($definition)];
        $anomalies = [...$anomalies, ...$this->validerPhases($definition)];
        $anomalies = [...$anomalies, ...$this->validerFlux($definition)];
        $anomalies = [...$anomalies, ...$this->validerFormats($definition)];
        $anomalies = [...$anomalies, ...$this->validerHandicap($definition)];

        if ($nbInscrits !== null) {
            $anomalies = [...$anomalies, ...$this->validerVolume($definition, $nbInscrits)];
        }

        return $anomalies;
    }

    /** @param list<Anomalie> $anomalies */
    public function ouvrable(array $anomalies): bool
    {
        foreach ($anomalies as $anomalie) {
            if ($anomalie->bloque()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Rapport lisible, tel qu'affiche a l'etape de verification.
     *
     * @param list<Anomalie> $anomalies
     */
    public function rapport(array $anomalies): string
    {
        if ($anomalies === []) {
            return '✔ Aucune anomalie : le tournoi peut être ouvert.';
        }

        $lignes = array_map(static fn (Anomalie $a): string => $a->afficher(), $anomalies);

        $lignes[] = $this->ouvrable($anomalies)
            ? '✔ Ouverture possible malgré les avertissements.'
            : '✖ Ouverture impossible : corriger les points bloquants.';

        return implode("\n", $lignes);
    }

    // -----------------------------------------------------------------
    // Parametres du tournoi
    // -----------------------------------------------------------------

    /** @return list<Anomalie> */
    private function validerParametres(DefinitionTournoi $definition): array
    {
        $anomalies = [];

        foreach ($definition->parametres as $code => $valeur) {
            if (! Catalogue::existe($code)) {
                $anomalies[] = Anomalie::avertissement(
                    "Paramètre « {$code} » inconnu du catalogue : il sera ignoré.",
                    null,
                    'tournoi'
                );
                continue;
            }

            if (! Catalogue::valeurAdmise($code, $valeur)) {
                $domaine = Catalogue::parametre($code)['domaine'];

                $anomalies[] = Anomalie::bloquante(
                    sprintf(
                        'Valeur hors domaine pour « %s » : %s (attendu %s).',
                        $code,
                        is_scalar($valeur) ? (string) $valeur : gettype($valeur),
                        is_array($domaine) ? implode(' | ', $domaine) : $domaine
                    ),
                    null,
                    'tournoi'
                );
            }
        }

        if (($definition->parametres['libelle'] ?? '') === '') {
            $anomalies[] = Anomalie::bloquante('Le tournoi doit porter un libellé.', null, 'tournoi');
        }

        return $anomalies;
    }

    // -----------------------------------------------------------------
    // Phases
    // -----------------------------------------------------------------

    /** @return list<Anomalie> */
    private function validerPhases(DefinitionTournoi $definition): array
    {
        $anomalies   = [];
        $generateurs = new Generateurs();
        $phases      = $definition->phasesOrdonnees();

        if ($phases === []) {
            return [Anomalie::bloquante('Le tournoi ne comporte aucune phase.', 'RG-30')];
        }

        $codes = [];

        foreach ($phases as $index => $phase) {
            if (isset($codes[$phase->code])) {
                $anomalies[] = Anomalie::bloquante(
                    "Deux phases portent le code « {$phase->code} ».",
                    null,
                    $phase->code
                );
            }

            $codes[$phase->code] = true;

            if (! $generateurs->existe($phase->type)) {
                $anomalies[] = Anomalie::bloquante(
                    sprintf(
                        'Type de phase inconnu : « %s ». Types admis : %s.',
                        $phase->type,
                        implode(', ', Generateurs::TYPES)
                    ),
                    null,
                    $phase->code
                );
                continue;
            }

            // RG-30 — toute phase autre que la premiere a un flux entrant.
            if ($index > 0 && $definition->fluxVers($phase->code) === []) {
                $anomalies[] = Anomalie::bloquante(
                    'Aucun flux entrant : personne ne peut atteindre cette phase.',
                    'RG-30',
                    $phase->code
                );
            }

            if ($phase->conditionActivation !== '') {
                $erreur = Expression::verifier($phase->conditionActivation, [
                    'nb_inscrits', 'nb_entrants', 'nb_qualifies', 'taille_tableau',
                ]);

                if ($erreur !== null) {
                    $anomalies[] = Anomalie::bloquante(
                        "Condition d'activation illisible : {$erreur}",
                        'RG-21',
                        $phase->code
                    );
                }
            }

            $anomalies = [
                ...$anomalies,
                ...$this->validerClassementPhase($definition, $phase),
            ];
        }

        return $anomalies;
    }

    /** @return list<Anomalie> */
    private function validerClassementPhase(DefinitionTournoi $definition, DefinitionPhase $phase): array
    {
        $anomalies  = [];
        $parametres = $definition->parametresPhase($phase);
        $cascade    = $phase->cascadeGroupe ?? $this->cascadeDeclaree($parametres);

        if ($cascade === null) {
            return $anomalies;
        }

        $format = FormatPartie::depuisParametres($parametres);

        // RG-51 — pas d'ex aequo exige un critere total en fin de cascade.
        $interdireExAequo = $parametres->estRenseigne('interdire_ex_aequo')
            ? $parametres->booleen('interdire_ex_aequo')
            : true;

        if ($interdireExAequo && ! $cascade->seTermineSurUnCritereTotal()) {
            $anomalies[] = Anomalie::bloquante(
                'La cascade n\'interdit pas les ex æquo : ajouter un critère total '
                . '(classement officiel, alphabétique ou tirage figé) en fin de liste.',
                'RG-51',
                $phase->code
            );
        }

        // RG-52 — manches seches en nombre pair : le 2-2 existe.
        if (
            $format->type === FormatPartie::MANCHES_SECHES
            && $format->nbManches % 2 === 0
            && $cascade->contient(Critere::Victoires)
        ) {
            $anomalies[] = Anomalie::avertissement(
                sprintf(
                    'Format en %d manches sèches : le match nul est possible, le critère '
                    . '« victoires » sera retiré automatiquement de la cascade.',
                    $format->nbManches
                ),
                'RG-52',
                $phase->code
            );
        }

        // RG-12 — quotients sur toute la poule et arret a l'acquis.
        $arret = $parametres->texte('regle_arret_rencontre', 'a_l_acquis');
        $typeEntite = $parametres->texte('type_entite', 'simple');

        if (
            $arret === 'a_l_acquis'
            && in_array($typeEntite, ['duo', 'equipe'], true)
            && $this->utiliseUnQuotientSurToutePoule($cascade)
        ) {
            $anomalies[] = Anomalie::bloquante(
                'La cascade utilise un quotient sur toute la poule alors que les rencontres '
                . 's\'arrêtent à l\'acquis : les parties non disputées manquent au dénominateur. '
                . 'Passer en « toutes parties jouées » ou restreindre la portée aux ex æquo.',
                'RG-12',
                $phase->code
            );
        }

        return $anomalies;
    }

    private function utiliseUnQuotientSurToutePoule(Cascade $cascade): bool
    {
        foreach ($cascade->etapesEffectives() as $etape) {
            $critere = $etape['critere'] ?? null;
            $portee  = $etape['portee'] ?? null;

            if (! $critere instanceof Critere) {
                continue;
            }

            $estQuotient = in_array($critere, [
                Critere::QuotientManches,
                Critere::QuotientPoints,
                Critere::RatioManches,
                Critere::RatioPoints,
            ], true);

            if ($estQuotient && ($portee?->value ?? '') === 'toute_la_poule') {
                return true;
            }
        }

        return false;
    }

    private function cascadeDeclaree(Parametres $parametres): ?Cascade
    {
        $criteres = $parametres->liste('criteres');

        if ($criteres === []) {
            return null;
        }

        try {
            return Cascade::depuisCodes($criteres);
        } catch (\Throwable) {
            return null;
        }
    }

    // -----------------------------------------------------------------
    // Flux
    // -----------------------------------------------------------------

    /** @return list<Anomalie> */
    private function validerFlux(DefinitionTournoi $definition): array
    {
        $anomalies = [];
        $codes     = array_map(
            static fn (DefinitionPhase $p): string => $p->code,
            $definition->phases
        );

        foreach ($definition->flux as $flux) {
            $lieu = $flux->description();

            if (! $flux->depuisInscriptions() && ! in_array($flux->phaseSource, $codes, true)) {
                $anomalies[] = Anomalie::bloquante(
                    "Phase source inconnue : « {$flux->phaseSource} ».",
                    null,
                    $lieu
                );
            }

            if (! in_array($flux->phaseCible, $codes, true)) {
                $anomalies[] = Anomalie::bloquante(
                    "Phase cible inconnue : « {$flux->phaseCible} ».",
                    null,
                    $lieu
                );
            }

            if ($flux->selecteur->exigeParametre() && $flux->parametre === null) {
                $anomalies[] = Anomalie::bloquante(
                    sprintf('Le sélecteur « %s » exige un paramètre.', $flux->selecteur->libelle()),
                    null,
                    $lieu
                );
            }

            if ($flux->phaseSource === $flux->phaseCible) {
                $anomalies[] = Anomalie::bloquante(
                    'Un flux ne peut pas boucler sur sa propre phase.',
                    null,
                    $lieu
                );
            }

            if (
                $flux->capaciteMax !== null
                && $flux->capaciteMax > 0
                && $flux->siSurnombre === Flux::SURNOMBRE_BARRAGE
            ) {
                $anomalies[] = Anomalie::information(
                    sprintf(
                        'Au-delà de %d qualifiés — et non de %d inscrits — un barrage sera '
                        . 'intercalé automatiquement.',
                        $flux->capaciteMax,
                        $flux->capaciteMax
                    ),
                    'RG-33',
                    $lieu
                );
            }
        }

        // Un cycle rendrait la generation infinie.
        if ($this->contientUnCycle($definition)) {
            $anomalies[] = Anomalie::bloquante(
                'Les flux forment un cycle : la génération ne pourrait pas se terminer.',
                'RG-30'
            );
        }

        return $anomalies;
    }

    private function contientUnCycle(DefinitionTournoi $definition): bool
    {
        $successeurs = [];

        foreach ($definition->flux as $flux) {
            $successeurs[$flux->phaseSource][] = $flux->phaseCible;
        }

        $etat = [];

        $visiter = function (string $noeud) use (&$visiter, &$etat, $successeurs): bool {
            if (($etat[$noeud] ?? 0) === 1) {
                return true;
            }

            if (($etat[$noeud] ?? 0) === 2) {
                return false;
            }

            $etat[$noeud] = 1;

            foreach ($successeurs[$noeud] ?? [] as $suivant) {
                if ($visiter($suivant)) {
                    return true;
                }
            }

            $etat[$noeud] = 2;

            return false;
        };

        foreach (array_keys($successeurs) as $noeud) {
            if ($visiter((string) $noeud)) {
                return true;
            }
        }

        return false;
    }

    // -----------------------------------------------------------------
    // Format de partie
    // -----------------------------------------------------------------

    /** @return list<Anomalie> */
    private function validerFormats(DefinitionTournoi $definition): array
    {
        $anomalies = [];

        foreach ($definition->phasesOrdonnees() as $phase) {
            $parametres = $definition->parametresPhase($phase);
            $format     = FormatPartie::depuisParametres($parametres);

            // RG-40 — les seuils suivent le nombre de points de la manche.
            $points = $format->pointsParManche;
            $seuil  = $parametres->entier('seuil_egalite');

            if ($seuil !== null && $seuil !== $points - 1) {
                $anomalies[] = Anomalie::avertissement(
                    sprintf(
                        'Manche à %d points mais égalité déclarée à %d : le seuil devrait être %d.',
                        $points,
                        $seuil,
                        $points - 1
                    ),
                    'RG-40',
                    $phase->code
                );
            }

            // ITTF 2.12.1 : une partie se joue au meilleur d'un nombre
            // IMPAIR de manches — donc 2 ou 3 manches gagnantes, soit
            // « au meilleur des 3 » et « au meilleur des 5 ». C'est le
            // nombre de manches SECHES en nombre pair qui pose probleme
            // (RG-52), pas le nombre de manches gagnantes.
            if ($format->type === FormatPartie::MANCHES_SECHES && $format->nbManches % 2 === 0) {
                $anomalies[] = Anomalie::information(
                    sprintf(
                        'Format en %d manches sèches : le score %d-%d est possible, '
                        . 'le classement se fera sur les ratios de manches.',
                        $format->nbManches,
                        intdiv($format->nbManches, 2),
                        intdiv($format->nbManches, 2)
                    ),
                    'RG-52',
                    $phase->code
                );
            }
        }

        // RG-54 — agreger des manches entre formats differents est faux.
        $formats = [];

        foreach ($definition->phasesOrdonnees() as $phase) {
            $format = FormatPartie::depuisParametres($definition->parametresPhase($phase));
            $formats[$format->type . ':' . $format->nbManches] = true;
        }

        $agregation = $definition->parametres()->texte('agregation_multi_phases', 'bareme_points');

        if (count($formats) > 1 && $agregation !== 'bareme_points' && $agregation !== 'interdite') {
            $anomalies[] = Anomalie::avertissement(
                'Le tournoi mélange plusieurs formats de partie : les manches ne sont pas '
                . 'commensurables d\'une phase à l\'autre. Agréger par barème de points.',
                'RG-54'
            );
        }

        return $anomalies;
    }

    // -----------------------------------------------------------------
    // Handicap
    // -----------------------------------------------------------------

    /** @return list<Anomalie> */
    private function validerHandicap(DefinitionTournoi $definition): array
    {
        $parametres = $definition->parametres();

        if (! $parametres->booleen('handicap_actif')) {
            return [];
        }

        $anomalies = [];
        $mode      = $parametres->texte('mode_calcul', 'formule');

        if ($mode === 'formule') {
            $formule = $parametres->texte('formule');

            if ($formule === '') {
                $anomalies[] = Anomalie::bloquante(
                    'Handicap actif en mode formule, mais aucune formule saisie.',
                    'RG-70',
                    'handicap'
                );
            } else {
                $erreur = Expression::verifier($formule, ['e', 'ecart']);

                if ($erreur !== null) {
                    $anomalies[] = Anomalie::bloquante(
                        "Formule de handicap invalide : {$erreur}",
                        'RG-70',
                        'handicap'
                    );
                }
            }
        } elseif ($parametres->table('table_valeurs') === []) {
            $anomalies[] = Anomalie::bloquante(
                'Handicap actif en mode table, mais la table des valeurs est vide.',
                'RG-70',
                'handicap'
            );
        }

        // RG-71 — le sens de l'echelle doit etre explicite.
        if (! $parametres->estRenseigne('sens_echelle')) {
            $anomalies[] = Anomalie::avertissement(
                'Sens de l\'échelle non précisé : un rang haut désigne-t-il un joueur fort ? '
                . 'Une inversion produit des handicaps aberrants sans jamais lever d\'erreur.',
                'RG-71',
                'handicap'
            );
        }

        // RG-72 — arrondi differe en methode « fort ajuste ».
        if (
            $parametres->texte('methode_double') === 'fort_ajuste'
            && $parametres->texte('arrondi_paire', 'differe') === 'immediat'
        ) {
            $anomalies[] = Anomalie::avertissement(
                'Arrondi immédiat en méthode « fort + ajustement » : biais systématique '
                . 'sur les paires. L\'arrondi doit être différé jusqu\'à la comparaison.',
                'RG-72',
                'handicap'
            );
        }

        // RG-77 — plafond et format de manche sont couples.
        $plafond = $parametres->entier('plafond', 8) ?? 8;

        foreach ($definition->phasesOrdonnees() as $phase) {
            $format = FormatPartie::depuisParametres($definition->parametresPhase($phase));

            if ($plafond > intdiv($format->pointsParManche, 2)) {
                $anomalies[] = Anomalie::avertissement(
                    sprintf(
                        'Handicap plafonné à %d points sur des manches à %d : la manche est '
                        . 'largement décidée avant d\'être jouée. Envisager une manche unique '
                        . 'à 21, 31 ou 33 points.',
                        $plafond,
                        $format->pointsParManche
                    ),
                    'RG-77',
                    $phase->code
                );
            }
        }

        return $anomalies;
    }

    // -----------------------------------------------------------------
    // Volume
    // -----------------------------------------------------------------

    /**
     * RG-91 — la soiree tient-elle dans le temps disponible ?
     *
     * @return list<Anomalie>
     */
    private function validerVolume(DefinitionTournoi $definition, int $nbInscrits): array
    {
        $anomalies  = [];
        $parametres = $definition->parametres();

        // On delegue au moteur plutot que de reimplementer le calcul :
        // deux estimations qui divergent seraient pires qu'une seule
        // approximative, parce qu'on ne saurait pas laquelle croire.
        $estimation = (new MoteurTournoi(new ResultatsEnMemoire()))
            ->estimer($definition, $nbInscrits);

        $parties = $estimation['parties'];

        $tables = $parametres->entier('nb_tables');
        $duree  = $parametres->entier('duree_estimee_partie') ?: 15;

        if ($tables === null || $tables < 1) {
            return [Anomalie::information(
                sprintf('Volume estimé : %d partie(s). Nombre de tables non renseigné.', $parties),
                'RG-91'
            )];
        }

        $minutes = (int) ceil($parties * $duree / $tables);

        $anomalies[] = Anomalie::information(
            sprintf(
                'Volume estimé : %d partie(s), soit %dh%02d sur %d table(s).',
                $parties,
                intdiv($minutes, 60),
                $minutes % 60,
                $tables
            ),
            'RG-91'
        );

        $limite = $parametres->texte('heure_limite');

        if ($limite !== '' && $minutes > $this->minutesDisponibles($limite)) {
            $anomalies[] = Anomalie::avertissement(
                sprintf(
                    'La soirée dépasse l\'heure limite de %s : %d minutes de jeu pour %d disponibles. '
                    . 'Réduire les poules, ajouter des tables ou raccourcir le format.',
                    $limite,
                    $minutes,
                    $this->minutesDisponibles($limite)
                ),
                'RG-91'
            );
        }

        return $anomalies;
    }

    /**
     * Minutes disponibles jusqu'a l'heure limite, depuis 19h30.
     *
     * L'heure de debut est celle du club ; la rendre configurable pour
     * une estimation ne vaut pas la complexite ajoutee.
     */
    private function minutesDisponibles(string $heureLimite): int
    {
        if (! preg_match('/^(\d{1,2})[:h](\d{2})$/', trim($heureLimite), $m)) {
            return PHP_INT_MAX;
        }

        $fin    = (int) $m[1] * 60 + (int) $m[2];
        $debut  = 19 * 60 + 30;

        if ($fin < $debut) {
            $fin += 24 * 60; // apres minuit
        }

        return $fin - $debut;
    }
}

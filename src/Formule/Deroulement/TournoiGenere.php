<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Deroulement;

use RMCF\Tournois\Formule\Structure\Appariement;
use RMCF\Tournois\Formule\Structure\PhaseGeneree;

/**
 * Le tournoi genere : toutes les phases actives, dans l'ordre.
 *
 * C'est ce qu'on affiche, ce qu'on imprime et ce qu'on ecrit en base.
 * Deux champs meritent l'attention :
 *
 *   - `ignorees` : les phases dont la condition d'activation etait
 *     fausse (RG-21). Les lister explicitement evite la question qui
 *     revient tous les soirs de tournoi — « pourquoi il n'y a pas de
 *     consolante ? » — en y repondant avant qu'elle soit posee.
 *
 *   - `avertissements` : agreges de toutes les phases. Un tournoi qui
 *     se genere sans avertissement est un tournoi ou l'organisateur a
 *     tout decide ; sinon, le moteur a decide pour lui, et il doit le
 *     savoir.
 */
final class TournoiGenere
{
    /**
     * @param array<string,PhaseGeneree> $phases   code => structure
     * @param array<string,string>       $ignorees code => raison
     * @param list<string>               $avertissements
     * @param array<string,mixed>        $meta
     */
    public function __construct(
        public readonly string $tournoi,
        public readonly array $phases = [],
        public readonly array $ignorees = [],
        public readonly array $avertissements = [],
        public readonly array $meta = [],
    ) {
    }

    public function phase(string $code): ?PhaseGeneree
    {
        return $this->phases[$code] ?? null;
    }

    /** @return list<string> */
    public function codesPhases(): array
    {
        return array_keys($this->phases);
    }

    /** Nombre total de parties reellement a jouer (les byes n'en sont pas). */
    public function nombreParties(): int
    {
        $total = 0;

        foreach ($this->phases as $phase) {
            $total += $phase->nombreParties();
        }

        return $total;
    }

    /** @return list<Appariement> tous les appariements, phase par phase */
    public function appariements(): array
    {
        $tous = [];

        foreach ($this->phases as $phase) {
            $tous = [...$tous, ...$phase->appariements];
        }

        return $tous;
    }

    /**
     * Les appariements immediatement lancables : les deux camps connus.
     *
     * C'est la file d'attente de la table de marque. Un match de tableau
     * dont l'adversaire n'est pas encore designe n'y figure pas.
     *
     * @return list<Appariement>
     */
    public function lancables(): array
    {
        return array_values(array_filter(
            $this->appariements(),
            static fn (Appariement $a): bool => $a->estLancable()
        ));
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'tournoi'        => $this->tournoi,
            'phases'         => array_map(
                static fn (PhaseGeneree $p): array => $p->enTableau(),
                $this->phases
            ),
            'ignorees'       => $this->ignorees,
            'nb_parties'     => $this->nombreParties(),
            'avertissements' => $this->avertissements,
            'meta'           => $this->meta,
        ];
    }

    /** Resume lisible — l'ecran de verification avant ouverture (§10.3). */
    public function resume(): string
    {
        $lignes = [sprintf('Tournoi « %s » — %d partie(s)', $this->tournoi, $this->nombreParties())];

        foreach ($this->phases as $phase) {
            $lignes[] = '  · ' . str_replace("\n", "\n  ", $phase->resume());
        }

        foreach ($this->ignorees as $code => $raison) {
            $lignes[] = sprintf('  · %s : ignorée (%s)', $code, $raison);
        }

        foreach ($this->avertissements as $avertissement) {
            $lignes[] = '  ⚠ ' . $avertissement;
        }

        return implode("\n", $lignes);
    }
}

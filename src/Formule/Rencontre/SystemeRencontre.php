<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Rencontre;

use InvalidArgumentException;

/**
 * Un systeme de rencontre : la sequence ordonnee des parties qui
 * composent une rencontre entre deux camps.
 *
 * RG-10 — la sequence est UNE DONNEE, jamais du code. Ajouter le
 * systeme d'une nouvelle federation ne doit exiger aucun deploiement,
 * seulement une ligne de configuration. C'est ce qui permet au module
 * de couvrir Swaythling, Corbillon, les seize simples des interclubs
 * FRBTT et la formule duo du club avec le meme moteur.
 *
 * ROLES
 *
 * Les parties se decrivent par des ROLES, pas par des noms : « A contre
 * X », « faible contre faible ». L'affectation des roles aux joueurs se
 * fait a la creation de la rencontre et se fige (RG-13) : un
 * reclassement en cours de saison ne doit pas reordonner des rencontres
 * deja creees.
 *
 * REGLE D'ARRET
 *
 *   a_l_acquis      la rencontre s'arrete des qu'un camp atteint la
 *                   majorite ; les parties restantes sont enregistrees
 *                   comme non disputees et ne comptent dans AUCUN
 *                   quotient (RG-11)
 *   toutes_parties  toutes les parties se jouent et comptent. Ce mode
 *                   est OBLIGATOIRE si la cascade de classement utilise
 *                   un quotient sur toute la poule (RG-12)
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class SystemeRencontre
{
    public const ARRET_A_L_ACQUIS   = 'a_l_acquis';
    public const ARRET_TOUTES       = 'toutes_parties';

    /**
     * @param list<array{
     *     ordre:int, a:string, b:string, type:string,
     *     conditionnelle?:bool, condition?:?string, libelle?:string
     * }> $parties
     */
    public function __construct(
        public readonly string $code,
        public readonly string $libelle,
        public readonly array $parties,
        public readonly string $regleArret = self::ARRET_A_L_ACQUIS,
        public readonly int $nbJoueursMin = 3,
        public readonly int $nbJoueursMax = 3,
        public readonly string $affectationRoles = 'par_classement_gele',
    ) {
        if ($parties === []) {
            throw new InvalidArgumentException('Un systeme de rencontre compte au moins une partie.');
        }
    }

    public function nbParties(): int
    {
        return count($this->parties);
    }

    /** Parties inconditionnelles, celles qui se jouent toujours. */
    public function nbPartiesFermes(): int
    {
        return count(array_filter(
            $this->parties,
            static fn (array $p): bool => !($p['conditionnelle'] ?? false)
        ));
    }

    /**
     * Victoires necessaires pour emporter la rencontre.
     *
     * En « toutes parties jouees », il n'y a pas de seuil d'arret : la
     * rencontre va au bout et se tranche au nombre de victoires.
     */
    public function victoiresPourGagner(): int
    {
        return intdiv($this->nbPartiesFermes(), 2) + 1;
    }

    public function sArreteALAcquis(): bool
    {
        return $this->regleArret === self::ARRET_A_L_ACQUIS;
    }

    /** Les roles distincts mobilises par le systeme, camp A puis camp B. */
    public function roles(): array
    {
        $a = [];
        $b = [];

        foreach ($this->parties as $partie) {
            foreach (explode('+', $partie['a']) as $role) {
                $a[trim($role)] = true;
            }

            foreach (explode('+', $partie['b']) as $role) {
                $b[trim($role)] = true;
            }
        }

        return ['a' => array_keys($a), 'b' => array_keys($b)];
    }

    /** Le systeme comporte-t-il une partie de double ? */
    public function comporteUnDouble(): bool
    {
        foreach ($this->parties as $partie) {
            if ($partie['type'] === 'double') {
                return true;
            }
        }

        return false;
    }

    /** Description courte, pour le panneau de resume. */
    public function description(): string
    {
        $simples = count(array_filter(
            $this->parties,
            static fn (array $p): bool => $p['type'] === 'simple'
        ));

        $doubles = $this->nbParties() - $simples;

        $morceaux = [sprintf('%d simple%s', $simples, $simples > 1 ? 's' : '')];

        if ($doubles > 0) {
            $morceaux[] = sprintf('%d double%s', $doubles, $doubles > 1 ? 's' : '');
        }

        return sprintf(
            '%s (%s, %s)',
            $this->libelle,
            implode(' + ', $morceaux),
            $this->sArreteALAcquis() ? 'arrêt à l\'acquis' : 'toutes parties jouées'
        );
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'code'         => $this->code,
            'libelle'      => $this->libelle,
            'regle_arret'  => $this->regleArret,
            'nb_parties'   => $this->nbParties(),
            'parties'      => $this->parties,
        ];
    }
}

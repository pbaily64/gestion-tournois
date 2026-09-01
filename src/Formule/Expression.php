<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule;

use InvalidArgumentException;

/**
 * Evaluateur d'expressions arithmetiques et logiques.
 *
 * Il sert a trois endroits ou le document demande explicitement une
 * expression saisie par l'organisateur, et non du code :
 *
 *   - la formule de handicap        : sign(e)*min(8; abs(e)/2+1)
 *   - la condition d'activation     : nb_inscrits > 24
 *   - la condition de partie decisive : victoires_a = 1 et victoires_b = 1
 *
 * C'est un analyseur descendant recursif, volontairement minuscule.
 * Il n'utilise PAS eval() : une formule est une donnee saisie dans un
 * formulaire, elle ne doit jamais pouvoir devenir du code PHP.
 *
 * Grammaire :
 *
 *   ou          := et ( 'ou' et )*
 *   et          := comparaison ( 'et' comparaison )*
 *   comparaison := somme ( ('='|'=='|'!='|'<>'|'<'|'<='|'>'|'>=') somme )?
 *   somme       := produit ( ('+'|'-') produit )*
 *   produit     := unaire ( ('*'|'/'|'%') unaire )*
 *   unaire      := ('-'|'+'|'non') unaire | puissance
 *   puissance   := primaire ( '^' unaire )?
 *   primaire    := nombre | fonction '(' args ')' | variable | '(' ou ')'
 *
 * Les arguments de fonction se separent par ';' ou par ',' : les deux
 * conventions circulent dans les reglements, autant les accepter.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class Expression
{
    /** @var list<array{0:string,1:string}> jeton = [type, valeur] */
    private array $jetons;

    private int $position = 0;

    /** @var array<string,float> */
    private array $variables;

    /** Fonctions reconnues, avec leur arite (null = variadique). */
    private const FONCTIONS = [
        'abs' => 1, 'sign' => 1, 'signe' => 1, 'sqrt' => 1, 'racine' => 1,
        'floor' => 1, 'plancher' => 1, 'ceil' => 1, 'plafond' => 1,
        'round' => null, 'arrondi' => null,
        'min' => null, 'max' => null,
        'si' => 3,
    ];

    /** @param array<string,float|int|bool> $variables */
    private function __construct(string $source, array $variables)
    {
        $this->jetons    = self::decouper($source);
        $this->variables = array_map(
            static fn (float|int|bool $v): float => (float) $v,
            $variables
        );
    }

    /**
     * Evalue une expression et rend un nombre.
     *
     * @param array<string,float|int|bool> $variables
     */
    public static function evaluer(string $source, array $variables = []): float
    {
        $expression = new self($source, $variables);
        $valeur     = $expression->ou();

        if ($expression->position < count($expression->jetons)) {
            throw new InvalidArgumentException(sprintf(
                'Expression mal formee : « %s » n\'a pas ete consomme en entier.',
                $source
            ));
        }

        return $valeur;
    }

    /**
     * Evalue une expression et rend un booleen.
     *
     * Toute valeur non nulle est vraie : « 1 » comme « 3 » valent vrai.
     *
     * @param array<string,float|int|bool> $variables
     */
    public static function evaluerCondition(string $source, array $variables = []): bool
    {
        return abs(self::evaluer($source, $variables)) > 1e-9;
    }

    /**
     * L'expression est-elle syntaxiquement correcte ?
     *
     * Sert a l'apercu en direct de l'etape 6 de l'ecran de definition :
     * on veut dire « formule invalide » pendant la saisie, pas au
     * premier match de la soiree.
     *
     * @param  list<string> $variables noms admis
     * @return string|null  message d'erreur, ou null si l'expression est valide
     */
    public static function verifier(string $source, array $variables = []): ?string
    {
        $bouchons = [];

        foreach ($variables as $nom) {
            $bouchons[$nom] = 1.0;
        }

        try {
            self::evaluer($source, $bouchons);

            return null;
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }

    // --- Analyse lexicale -------------------------------------------

    /** @return list<array{0:string,1:string}> */
    private static function decouper(string $source): array
    {
        $jetons = [];
        $i      = 0;
        $n      = strlen($source);

        while ($i < $n) {
            $c = $source[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }

            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($source[$i + 1]))) {
                $debut = $i;

                while ($i < $n && (ctype_digit($source[$i]) || $source[$i] === '.')) {
                    $i++;
                }

                $jetons[] = ['nombre', substr($source, $debut, $i - $debut)];
                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                $debut = $i;

                while ($i < $n && (ctype_alnum($source[$i]) || $source[$i] === '_')) {
                    $i++;
                }

                $jetons[] = ['mot', strtolower(substr($source, $debut, $i - $debut))];
                continue;
            }

            // Operateurs a deux caracteres.
            if ($i + 1 < $n) {
                $double = substr($source, $i, 2);

                if (in_array($double, ['==', '!=', '<>', '<=', '>=', '&&', '||'], true)) {
                    $jetons[] = ['op', $double];
                    $i += 2;
                    continue;
                }
            }

            if (str_contains('+-*/%^()<>=;,', $c)) {
                $jetons[] = ['op', $c];
                $i++;
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'Caractere inattendu dans l\'expression : « %s ».',
                $c
            ));
        }

        return $jetons;
    }

    // --- Analyse syntaxique -----------------------------------------

    private function ou(): float
    {
        $gauche = $this->et();

        while ($this->motEst('ou') || $this->opEst('||')) {
            $this->position++;
            $droite = $this->et();
            $gauche = ($gauche != 0.0 || $droite != 0.0) ? 1.0 : 0.0;
        }

        return $gauche;
    }

    private function et(): float
    {
        $gauche = $this->comparaison();

        while ($this->motEst('et') || $this->opEst('&&')) {
            $this->position++;
            $droite = $this->comparaison();
            $gauche = ($gauche != 0.0 && $droite != 0.0) ? 1.0 : 0.0;
        }

        return $gauche;
    }

    private function comparaison(): float
    {
        $gauche = $this->somme();

        foreach (['<=', '>=', '==', '!=', '<>', '=', '<', '>'] as $op) {
            if (!$this->opEst($op)) {
                continue;
            }

            $this->position++;
            $droite = $this->somme();

            return match ($op) {
                '=', '=='  => abs($gauche - $droite) < 1e-9 ? 1.0 : 0.0,
                '!=', '<>' => abs($gauche - $droite) < 1e-9 ? 0.0 : 1.0,
                '<'        => $gauche < $droite ? 1.0 : 0.0,
                '<='       => $gauche <= $droite ? 1.0 : 0.0,
                '>'        => $gauche > $droite ? 1.0 : 0.0,
                '>='       => $gauche >= $droite ? 1.0 : 0.0,
                default    => 0.0,
            };
        }

        return $gauche;
    }

    private function somme(): float
    {
        $valeur = $this->produit();

        while ($this->opEst('+') || $this->opEst('-')) {
            $op = $this->jetons[$this->position][1];
            $this->position++;
            $droite = $this->produit();
            $valeur = $op === '+' ? $valeur + $droite : $valeur - $droite;
        }

        return $valeur;
    }

    private function produit(): float
    {
        $valeur = $this->unaire();

        while ($this->opEst('*') || $this->opEst('/') || $this->opEst('%')) {
            $op = $this->jetons[$this->position][1];
            $this->position++;
            $droite = $this->unaire();

            if (($op === '/' || $op === '%') && abs($droite) < 1e-12) {
                throw new InvalidArgumentException('Division par zero dans l\'expression.');
            }

            $valeur = match ($op) {
                '*'     => $valeur * $droite,
                '/'     => $valeur / $droite,
                default => fmod($valeur, $droite),
            };
        }

        return $valeur;
    }

    private function unaire(): float
    {
        if ($this->opEst('-')) {
            $this->position++;

            return -$this->unaire();
        }

        if ($this->opEst('+')) {
            $this->position++;

            return $this->unaire();
        }

        if ($this->motEst('non')) {
            $this->position++;

            return $this->unaire() != 0.0 ? 0.0 : 1.0;
        }

        return $this->puissance();
    }

    private function puissance(): float
    {
        $base = $this->primaire();

        if ($this->opEst('^')) {
            $this->position++;

            return $base ** $this->unaire();
        }

        return $base;
    }

    private function primaire(): float
    {
        if ($this->position >= count($this->jetons)) {
            throw new InvalidArgumentException('Expression incomplete.');
        }

        [$type, $valeur] = $this->jetons[$this->position];

        if ($type === 'nombre') {
            $this->position++;

            return (float) $valeur;
        }

        if ($type === 'op' && $valeur === '(') {
            $this->position++;
            $interieur = $this->ou();
            $this->attendre(')');

            return $interieur;
        }

        if ($type === 'mot') {
            $this->position++;

            if ($this->opEst('(')) {
                return $this->appliquerFonction($valeur);
            }

            if (array_key_exists($valeur, $this->variables)) {
                return $this->variables[$valeur];
            }

            if ($valeur === 'vrai') {
                return 1.0;
            }

            if ($valeur === 'faux') {
                return 0.0;
            }

            throw new InvalidArgumentException(sprintf(
                'Variable inconnue dans l\'expression : « %s ».',
                $valeur
            ));
        }

        throw new InvalidArgumentException(sprintf(
            'Jeton inattendu dans l\'expression : « %s ».',
            $valeur
        ));
    }

    private function appliquerFonction(string $nom): float
    {
        if (!array_key_exists($nom, self::FONCTIONS)) {
            throw new InvalidArgumentException(sprintf(
                'Fonction inconnue dans l\'expression : « %s ».',
                $nom
            ));
        }

        $this->attendre('(');
        $arguments = [];

        if (!$this->opEst(')')) {
            $arguments[] = $this->ou();

            while ($this->opEst(';') || $this->opEst(',')) {
                $this->position++;
                $arguments[] = $this->ou();
            }
        }

        $this->attendre(')');

        $arite = self::FONCTIONS[$nom];

        if ($arite !== null && count($arguments) !== $arite) {
            throw new InvalidArgumentException(sprintf(
                'La fonction %s attend %d argument(s).',
                $nom,
                $arite
            ));
        }

        if ($arguments === []) {
            throw new InvalidArgumentException(sprintf('La fonction %s attend au moins un argument.', $nom));
        }

        return match ($nom) {
            'abs'                => abs($arguments[0]),
            'sign', 'signe'      => $arguments[0] <=> 0.0,
            'sqrt', 'racine'     => sqrt(max(0.0, $arguments[0])),
            'floor', 'plancher'  => floor($arguments[0]),
            'ceil', 'plafond'    => ceil($arguments[0]),
            'round', 'arrondi'   => round($arguments[0], (int) ($arguments[1] ?? 0)),
            'min'                => min($arguments),
            'max'                => max($arguments),
            'si'                 => $arguments[0] != 0.0 ? $arguments[1] : $arguments[2],
            default              => throw new InvalidArgumentException('Fonction non implementee.'),
        };
    }

    // --- Utilitaires ------------------------------------------------

    private function opEst(string $op): bool
    {
        return isset($this->jetons[$this->position])
            && $this->jetons[$this->position][0] === 'op'
            && $this->jetons[$this->position][1] === $op;
    }

    private function motEst(string $mot): bool
    {
        return isset($this->jetons[$this->position])
            && $this->jetons[$this->position][0] === 'mot'
            && $this->jetons[$this->position][1] === $mot;
    }

    private function attendre(string $op): void
    {
        if (!$this->opEst($op)) {
            throw new InvalidArgumentException(sprintf(
                'Il manque « %s » dans l\'expression.',
                $op
            ));
        }

        $this->position++;
    }
}

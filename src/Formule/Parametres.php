<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule;

/**
 * Resolution d'un parametre par heritage et surcharge.
 *
 * Un parametre se regle a l'un de ces cinq niveaux ; la valeur du
 * niveau le plus fin l'emporte (annexe C.1) :
 *
 *     tournoi -> phase -> groupe -> tour -> partie
 *
 * C'est ce mecanisme qui permet d'ecrire « trois manches gagnantes
 * partout, sauf la finale en quatre gagnantes » sans dupliquer la
 * configuration. Il est implemente UNE fois, ici, et non parametre par
 * parametre : ajouter un reglage surchargeable ne demande aucun code.
 *
 * Quand aucun niveau ne renseigne le parametre, le defaut du catalogue
 * s'applique. Aucun defaut n'est ecrit ailleurs.
 *
 * L'objet est immuable : avec() rend une nouvelle instance. Deux
 * parties d'un meme tour peuvent donc partir de la meme chaine sans
 * risque de se contaminer.
 *
 * Aucun acces a la base ni au HTML : cette classe est testable seule.
 */
final class Parametres
{
    /** @param list<array<string,mixed>> $niveaux du plus large au plus fin */
    private function __construct(private readonly array $niveaux)
    {
    }

    /**
     * Construit une chaine, du niveau le plus large au plus fin.
     *
     * @param array<string,mixed> ...$niveaux
     */
    public static function chaine(array ...$niveaux): self
    {
        return new self(array_values($niveaux));
    }

    public static function vide(): self
    {
        return new self([]);
    }

    /**
     * Ajoute un niveau plus fin.
     *
     * @param array<string,mixed> $surcharge
     */
    public function avec(array $surcharge): self
    {
        return new self([...$this->niveaux, $surcharge]);
    }

    /**
     * Valeur effective du parametre.
     *
     * On remonte du niveau le plus fin vers le plus large et on retient
     * la premiere valeur non nulle. Une valeur explicitement fausse ou
     * egale a zero est une valeur : seul null signifie « non renseigne,
     * herite du dessus ».
     */
    public function valeur(string $code): mixed
    {
        for ($i = count($this->niveaux) - 1; $i >= 0; $i--) {
            if (array_key_exists($code, $this->niveaux[$i]) && $this->niveaux[$i][$code] !== null) {
                return $this->niveaux[$i][$code];
            }
        }

        return Catalogue::existe($code) ? Catalogue::defaut($code) : null;
    }

    /** Le parametre est-il renseigne a un niveau quelconque ? */
    public function estRenseigne(string $code): bool
    {
        foreach ($this->niveaux as $niveau) {
            if (array_key_exists($code, $niveau) && $niveau[$code] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * A quel niveau le parametre a-t-il ete fixe ?
     *
     * Sert a l'affichage : « 4 manches gagnantes (surcharge au tour) ».
     * Rend null si la valeur vient du catalogue.
     */
    public function niveauDeDefinition(string $code): ?int
    {
        for ($i = count($this->niveaux) - 1; $i >= 0; $i--) {
            if (array_key_exists($code, $this->niveaux[$i]) && $this->niveaux[$i][$code] !== null) {
                return $i;
            }
        }

        return null;
    }

    public function entier(string $code, ?int $defaut = null): ?int
    {
        $valeur = $this->valeur($code);

        if ($valeur === null || $valeur === 'auto' || $valeur === 'equilibree') {
            return $defaut;
        }

        if ($valeur === 'infini') {
            return PHP_INT_MAX;
        }

        return (int) $valeur;
    }

    public function booleen(string $code): bool
    {
        return (bool) $this->valeur($code);
    }

    public function texte(string $code, string $defaut = ''): string
    {
        $valeur = $this->valeur($code);

        return is_scalar($valeur) ? (string) $valeur : $defaut;
    }

    /** @return list<mixed> */
    public function liste(string $code): array
    {
        $valeur = $this->valeur($code);

        return is_array($valeur) ? array_values($valeur) : [];
    }

    /** @return array<string,mixed> */
    public function table(string $code): array
    {
        $valeur = $this->valeur($code);

        return is_array($valeur) ? $valeur : [];
    }

    /** La valeur est-elle la chaine speciale « auto » ? */
    public function estAuto(string $code): bool
    {
        return in_array($this->valeur($code), ['auto', 'equilibree', 'infini'], true);
    }

    /**
     * Toutes les valeurs effectives, catalogue compris.
     *
     * @return array<string,mixed>
     */
    public function tous(): array
    {
        $tous = Catalogue::defauts();

        foreach ($this->niveaux as $niveau) {
            foreach ($niveau as $code => $valeur) {
                if ($valeur !== null) {
                    $tous[$code] = $valeur;
                }
            }
        }

        return $tous;
    }

    /**
     * Le champ doit-il s'afficher dans l'ecran de definition ?
     *
     * Le catalogue exprime la dependance sous trois formes, choisies
     * pour rester lisibles dans le tableau :
     *
     *     'gel_classement'                 le parametre est vrai
     *     'type_phase = poules'            egalite
     *     'type_entite in double,duo'      appartenance
     *     'contrainte_composition not aucune'  difference
     */
    public function visible(string $code): bool
    {
        $condition = Catalogue::parametre($code)['visible_si'] ?? null;

        if ($condition === null) {
            return true;
        }

        return $this->conditionSatisfaite($condition);
    }

    public function conditionSatisfaite(string $condition): bool
    {
        $morceaux = preg_split('/\s+/', trim($condition), 3) ?: [];

        if (count($morceaux) === 1) {
            return (bool) $this->valeur($morceaux[0]);
        }

        if (count($morceaux) < 3) {
            return true;
        }

        [$code, $operateur, $attendu] = $morceaux;
        $valeur = $this->valeur($code);

        return match ($operateur) {
            '='     => (string) $valeur === $attendu,
            'not'   => (string) $valeur !== $attendu,
            'in'    => in_array((string) $valeur, explode(',', $attendu), true),
            'notin' => !in_array((string) $valeur, explode(',', $attendu), true),
            default => true,
        };
    }
}

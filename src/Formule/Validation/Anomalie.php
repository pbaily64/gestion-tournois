<?php

declare(strict_types=1);

namespace RMCF\Tournois\Formule\Validation;

/**
 * Un constat de validation.
 *
 * La distinction entre BLOQUANT et AVERTISSEMENT est la decision la plus
 * importante de tout le validateur, et elle merite d'etre prise avec
 * parcimonie. Bloquer, c'est empecher un organisateur d'ouvrir son
 * tournoi ; un validateur trop severe finit contourne, et un validateur
 * contourne ne protege plus rien.
 *
 * La regle retenue : on ne bloque que ce qui rendrait le tournoi
 * INCOHERENT (un classement impossible a calculer, une phase que
 * personne ne peut atteindre). Tout ce qui rendrait le tournoi
 * seulement DESAGREABLE — une poule de 9, une soiree qui finira a
 * minuit — est un avertissement : c'est le droit de l'organisateur de
 * savoir et de passer outre.
 *
 * Chaque anomalie porte sa regle de gestion, pour qu'on puisse remonter
 * de l'ecran a l'annexe C sans chercher.
 */
final class Anomalie
{
    public const BLOQUANT      = 'bloquant';
    public const AVERTISSEMENT = 'avertissement';
    public const INFORMATION   = 'information';

    public function __construct(
        public readonly string $niveau,
        public readonly string $message,
        public readonly ?string $regle = null,
        public readonly ?string $emplacement = null,
    ) {
    }

    public static function bloquante(string $message, ?string $regle = null, ?string $ou = null): self
    {
        return new self(self::BLOQUANT, $message, $regle, $ou);
    }

    public static function avertissement(string $message, ?string $regle = null, ?string $ou = null): self
    {
        return new self(self::AVERTISSEMENT, $message, $regle, $ou);
    }

    public static function information(string $message, ?string $regle = null, ?string $ou = null): self
    {
        return new self(self::INFORMATION, $message, $regle, $ou);
    }

    public function bloque(): bool
    {
        return $this->niveau === self::BLOQUANT;
    }

    public function afficher(): string
    {
        $prefixe = match ($this->niveau) {
            self::BLOQUANT      => '✖',
            self::AVERTISSEMENT => '⚠',
            default             => 'ℹ',
        };

        $suffixe = $this->regle !== null ? ' (' . $this->regle . ')' : '';
        $lieu    = $this->emplacement !== null ? '[' . $this->emplacement . '] ' : '';

        return $prefixe . ' ' . $lieu . $this->message . $suffixe;
    }

    /** @return array<string,mixed> */
    public function enTableau(): array
    {
        return [
            'niveau'      => $this->niveau,
            'message'     => $this->message,
            'regle'       => $this->regle,
            'emplacement' => $this->emplacement,
        ];
    }
}

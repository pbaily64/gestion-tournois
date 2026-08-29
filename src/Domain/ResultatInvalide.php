<?php

declare(strict_types=1);

namespace RMCF\Tournois\Domain;

use RuntimeException;

/**
 * Un score encode ne respecte pas les regles du jeu ou le format retenu
 * pour la phase.
 *
 * Le message est destine a l'organisateur : il doit dire ce qui ne va
 * pas et permettre de corriger sans deviner.
 */
final class ResultatInvalide extends RuntimeException
{
}

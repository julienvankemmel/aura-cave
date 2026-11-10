<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Compatibility\Wine\AppellationRules;
use App\Enum\CheckoutStepEnum;

/**
 * Détection déterministe d'une demande de correction (« je me suis trompé d'adresse »), sur la saisie brute, côté
 * Symfony : le texte du client n'est jamais confié au LLM pour en deviner l'intention.
 */
final class EditIntent
{
    private const CORRECTION = '/\b(?:me suis tromp\w*|erreur|corriger|corrige|modifier|modifie|changer|change|rectifier|rectifie|faux|fausse|incorrecte?|mauvais\w*|ce n est pas)\b/';
    private const GIVE_UP = '/\b(?:rien|aucun\w*|laisse\w*|tant pis|non|annule\w*)\b/';

    public static function isCorrection(string $raw): bool
    {
        $text = AppellationRules::normalize($raw);

        return mb_strlen($raw) <= 160 && 1 === preg_match(self::CORRECTION, $text);
    }

    public static function givesUp(string $raw): bool
    {
        return 1 === preg_match(self::GIVE_UP, AppellationRules::normalize($raw));
    }

    /** Champ visé par le texte, ou null s'il ne le dit pas. « adresse mail » vise l'email, pas l'adresse postale. */
    public static function field(string $raw): ?CheckoutStepEnum
    {
        $text = AppellationRules::normalize($raw);

        return match (true) {
            1 === preg_match('/\b(?:e ?mail|mail|courriel)\b/', $text) => CheckoutStepEnum::AwaitingEmail,
            1 === preg_match('/\b(?:telephone|tel|numero|portable|mobile)\b/', $text) => CheckoutStepEnum::AwaitingPhone,
            1 === preg_match('/\b(?:nom|prenom)\b/', $text) => CheckoutStepEnum::AwaitingFullName,
            1 === preg_match('/\b(?:adresse|rue|livraison|domicile|code postal|ville)\b/', $text) => CheckoutStepEnum::AwaitingAddress,
            default => null,
        };
    }
}

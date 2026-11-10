<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Enum\CheckoutStepEnum;

/**
 * Validation de format basique, propre à chaque étape. Renvoie la valeur normalisée, ou null si elle est invalide.
 */
final class FieldValidator
{
    public static function normalize(CheckoutStepEnum $step, string $raw): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $raw));
        if ('' === $value) {
            return null;
        }

        return match ($step) {
            CheckoutStepEnum::AwaitingFullName => self::fullName($value),
            CheckoutStepEnum::AwaitingAddress => self::address($value),
            CheckoutStepEnum::AwaitingPhone => self::phone($value),
            CheckoutStepEnum::AwaitingEmail => self::email($value),
            CheckoutStepEnum::Confirmed => null,
        };
    }

    /** Au moins deux mots composés de lettres (tirets, apostrophes et points admis). */
    private static function fullName(string $value): ?string
    {
        if (mb_strlen($value) < 3 || mb_strlen($value) > 120) {
            return null;
        }

        return 1 === preg_match("/^\\p{L}[\\p{L}.'’-]*(?: \\p{L}[\\p{L}.'’-]*)+$/u", $value) ? $value : null;
    }

    /** Longueur minimale et au moins un chiffre (numéro ou code postal). */
    private static function address(string $value): ?string
    {
        if (mb_strlen($value) < 10 || mb_strlen($value) > 255 || 1 !== preg_match('/\d/', $value) || 1 !== preg_match('/\p{L}{2}/u', $value)) {
            return null;
        }

        return $value;
    }

    /** Numéro français à 10 chiffres (0X XX XX XX XX), ou format international +33 / 0033. */
    private static function phone(string $value): ?string
    {
        $compact = (string) preg_replace('/[\s.\-()]/', '', $value);
        $compact = (string) preg_replace('/^(?:\+33|0033)/', '0', $compact);

        return 1 === preg_match('/^0[1-9]\d{8}$/', $compact) ? $compact : null;
    }

    private static function email(string $value): ?string
    {
        $email = strtolower($value);

        return mb_strlen($email) <= 180 && false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}

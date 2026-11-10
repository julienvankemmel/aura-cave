<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Étapes séquentielles de la collecte des coordonnées de livraison, dans le chat. Chaque étape n'accepte que sa donnée.
 */
enum CheckoutStepEnum: string
{
    case AwaitingFullName = 'AWAITING_FULL_NAME';
    case AwaitingAddress = 'AWAITING_ADDRESS';
    case AwaitingPhone = 'AWAITING_PHONE';
    case AwaitingEmail = 'AWAITING_EMAIL';
    case Confirmed = 'CONFIRMED';

    /** Étape suivante ; `Confirmed` est terminale. */
    public function next(): self
    {
        return match ($this) {
            self::AwaitingFullName => self::AwaitingAddress,
            self::AwaitingAddress => self::AwaitingPhone,
            self::AwaitingPhone => self::AwaitingEmail,
            self::AwaitingEmail, self::Confirmed => self::Confirmed,
        };
    }

    public function isCollecting(): bool
    {
        return self::Confirmed !== $this;
    }

    /** Rang dans la séquence (0 = nom … 3 = email, 4 = confirmée). */
    public function rank(): int
    {
        return match ($this) {
            self::AwaitingFullName => 0,
            self::AwaitingAddress => 1,
            self::AwaitingPhone => 2,
            self::AwaitingEmail => 3,
            self::Confirmed => 4,
        };
    }

    /** Étape qui collecte le champ donné (`name`, `address`, `phone`, `email`). */
    public static function forField(string $field): ?self
    {
        return match ($field) {
            'name', 'full_name' => self::AwaitingFullName,
            'address' => self::AwaitingAddress,
            'phone' => self::AwaitingPhone,
            'email' => self::AwaitingEmail,
            default => null,
        };
    }

    /** Ce que le client souhaite corriger, pour une consigne au LLM. */
    public function subject(): string
    {
        return match ($this) {
            self::AwaitingFullName => 'son nom et son prénom',
            self::AwaitingAddress => 'son adresse de livraison',
            self::AwaitingPhone => 'son numéro de téléphone',
            self::AwaitingEmail => 'son adresse email',
            self::Confirmed => '',
        };
    }

    /** Clé de la donnée collectée à cette étape (null une fois confirmée). */
    public function field(): ?string
    {
        return match ($this) {
            self::AwaitingFullName => 'full_name',
            self::AwaitingAddress => 'address',
            self::AwaitingPhone => 'phone',
            self::AwaitingEmail => 'email',
            self::Confirmed => null,
        };
    }

    /** Jeton d'anonymisation : seule valeur que le LLM voit à la place de la donnée saisie. */
    public function placeholder(): ?string
    {
        return match ($this) {
            self::AwaitingFullName => '[CUSTOMER_NAME]',
            self::AwaitingAddress => '[SHIPPING_ADDRESS]',
            self::AwaitingPhone => '[CUSTOMER_PHONE]',
            self::AwaitingEmail => '[CUSTOMER_EMAIL]',
            self::Confirmed => null,
        };
    }

    /** Question à poser au client à cette étape (formulation naturelle suggérée au LLM). */
    public function question(): string
    {
        return match ($this) {
            self::AwaitingFullName => 'À quel nom et prénom devons-nous adresser la commande ?',
            self::AwaitingAddress => 'Quelle est votre adresse postale de livraison ?',
            self::AwaitingPhone => 'À quel numéro de téléphone le livreur peut-il vous joindre ?',
            self::AwaitingEmail => 'Et enfin, quelle est votre adresse email pour le suivi ?',
            self::Confirmed => '',
        };
    }

    /** Ce que l'on attend du client, pour une correction polie. */
    public function expected(): string
    {
        return match ($this) {
            self::AwaitingFullName => 'ses nom et prénom',
            self::AwaitingAddress => 'son adresse postale (numéro, rue, code postal, ville)',
            self::AwaitingPhone => 'un numéro de téléphone à 10 chiffres',
            self::AwaitingEmail => 'une adresse email valide',
            self::Confirmed => '',
        };
    }
}

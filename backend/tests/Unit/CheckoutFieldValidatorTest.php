<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Checkout\FieldValidator;
use App\Enum\CheckoutStepEnum as Step;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckoutFieldValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{Step, string, string|null}>
     */
    public static function cases(): iterable
    {
        yield 'nom valide' => [Step::AwaitingFullName, '  Marie   Dupont ', 'Marie Dupont'];
        yield 'nom composé' => [Step::AwaitingFullName, "Jean-Pierre d'Aubigné", "Jean-Pierre d'Aubigné"];
        yield 'nom sans prénom' => [Step::AwaitingFullName, 'Dupont', null];
        yield 'nom avec chiffres' => [Step::AwaitingFullName, 'Marie 75002', null];
        yield 'nom qui est un email' => [Step::AwaitingFullName, 'marie@example.org', null];
        yield 'adresse valide' => [Step::AwaitingAddress, '12 rue de la Paix 75002 Paris', '12 rue de la Paix 75002 Paris'];
        yield 'adresse trop courte' => [Step::AwaitingAddress, '12 rue', null];
        yield 'adresse sans chiffre' => [Step::AwaitingAddress, 'rue de la Paix à Paris', null];
        yield 'téléphone espacé' => [Step::AwaitingPhone, '06 12 34 56 78', '0612345678'];
        yield 'téléphone international' => [Step::AwaitingPhone, '+33 6 12 34 56 78', '0612345678'];
        yield 'téléphone à points' => [Step::AwaitingPhone, '01.23.45.67.89', '0123456789'];
        yield 'téléphone trop court' => [Step::AwaitingPhone, '06 12 34', null];
        yield 'téléphone avec lettres' => [Step::AwaitingPhone, '06 12 34 56 7a', null];
        yield 'email valide' => [Step::AwaitingEmail, 'Marie.Dupont@Example.org', 'marie.dupont@example.org'];
        yield 'email invalide' => [Step::AwaitingEmail, 'marie.dupont@', null];
        yield 'vide' => [Step::AwaitingFullName, '   ', null];
        yield 'étape terminale' => [Step::Confirmed, 'Marie Dupont', null];
    }

    #[DataProvider('cases')]
    public function testEachStepOnlyAcceptsItsOwnData(Step $step, string $raw, ?string $expected): void
    {
        self::assertSame($expected, FieldValidator::normalize($step, $raw));
    }

    public function testAStepRejectsTheDataOfOtherSteps(): void
    {
        $samples = ['full_name' => 'Marie Dupont', 'address' => '12 rue de la Paix 75002 Paris', 'phone' => '06 12 34 56 78', 'email' => 'marie@example.org'];
        foreach ([Step::AwaitingFullName, Step::AwaitingAddress, Step::AwaitingPhone, Step::AwaitingEmail] as $step) {
            foreach ($samples as $field => $sample) {
                $accepted = null !== FieldValidator::normalize($step, $sample);
                self::assertSame($step->field() === $field, $accepted, $step->value.' avec '.$field);
            }
        }
    }

    public function testStepsFormAStrictSequence(): void
    {
        $order = [];
        $step = Step::AwaitingFullName;
        while ($step->isCollecting()) {
            $order[] = $step->value;
            self::assertNotNull($step->placeholder());
            $step = $step->next();
        }
        self::assertSame(['AWAITING_FULL_NAME', 'AWAITING_ADDRESS', 'AWAITING_PHONE', 'AWAITING_EMAIL'], $order);
        self::assertSame(Step::Confirmed, $step);
        self::assertSame(Step::Confirmed, $step->next());
    }

    /**
     * @return iterable<string, array{string, bool, string|null}>
     */
    public static function corrections(): iterable
    {
        yield 'adresse' => ["Je me suis trompé d'adresse", true, 'AWAITING_ADDRESS'];
        yield 'numéro' => ['oups erreur sur le numéro de téléphone', true, 'AWAITING_PHONE'];
        yield 'adresse mail vise l\'email' => ['je veux changer mon adresse mail', true, 'AWAITING_EMAIL'];
        yield 'nom' => ['je dois corriger mon prénom', true, 'AWAITING_FULL_NAME'];
        yield 'sans champ' => ['je me suis trompé', true, null];
        yield 'nom de famille voisin d\'un mot clé' => ['Marie Corrigan', false, null];
        yield 'adresse normale' => ['12 rue de la Paix 75002 Paris', false, 'AWAITING_ADDRESS'];
    }

    #[DataProvider('corrections')]
    public function testNaturalLanguageCorrectionsAreRecognizedDeterministically(string $text, bool $isCorrection, ?string $field): void
    {
        self::assertSame($isCorrection, \App\Checkout\EditIntent::isCorrection($text));
        self::assertSame($field, \App\Checkout\EditIntent::field($text)?->value);
    }
}

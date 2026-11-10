<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Cart;
use App\Entity\Order;
use Symfony\Component\Uid\Uuid;

/**
 * Parcours complet du checkout conversationnel : une donnée par message, pseudonymisation immédiate, commande finale.
 */
final class ConversationCheckoutTest extends ApiTestCase
{
    private const NAME = 'Marie Dupont';
    private const ADDRESS = '12 rue de la Paix 75002 Paris';
    private const PHONE = '06 12 34 56 78';
    private const EMAIL = 'marie.dupont@example.org';

    /** Tout ce qui permettrait de reconnaître le client si cela atteignait le LLM. */
    private const SECRETS = ['Marie', 'Dupont', 'rue de la Paix', '75002', '0612345678', '06 12 34 56 78', 'marie.dupont', 'example.org'];

    private function cartUri(string $suffix = ''): string
    {
        return '/api/cart/'.self::CART_ID.'/items'.$suffix;
    }

    private function checkout(string $action): string
    {
        return '/api/checkout/'.self::CART_ID.'/'.$action;
    }

    private function fillCart(): void
    {
        $this->api('POST', $this->cartUri(), ['product_id' => $this->productId('PICHON-BARON-2016')]);
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertNothingPersonal(array $payload, string $context): void
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $encoded, $context.' ne doit pas contenir « '.$secret.' »');
        }
    }

    public function testFullJourneyCollectsOneFieldPerMessageAndCreatesTheOrder(): void
    {
        $this->fillCart();

        $start = $this->api('POST', $this->checkout('start'));
        self::assertResponseIsSuccessful();
        self::assertSame('AWAITING_FULL_NAME', $start['step']);
        self::assertStringContainsString('nom et prénom', (string) $start['instruction']);

        $steps = [
            [self::NAME, '[CUSTOMER_NAME]', 'AWAITING_ADDRESS', 'adresse postale'],
            [self::ADDRESS, '[SHIPPING_ADDRESS]', 'AWAITING_PHONE', 'numéro de téléphone'],
            [self::PHONE, '[CUSTOMER_PHONE]', 'AWAITING_EMAIL', 'adresse email'],
        ];
        foreach ($steps as [$raw, $token, $nextStep, $nextQuestion]) {
            $turn = $this->api('POST', $this->checkout('message'), ['text' => $raw]);
            self::assertResponseIsSuccessful();
            self::assertTrue($turn['accepted']);
            self::assertSame($token, $turn['llm_message'], 'seul le jeton est destiné au LLM');
            self::assertSame($nextStep, $turn['step']);
            self::assertStringContainsString($token, (string) $turn['instruction'], 'la consigne cite le jeton de la donnée validée');
            self::assertStringContainsString($nextQuestion, (string) $turn['instruction']);
            self::assertNull($turn['order']);
            $this->assertNothingPersonal($turn, 'la réponse '.$nextStep);

            $hint = $this->api('POST', $this->checkout('hint'));
            self::assertTrue($hint['active']);
            self::assertSame($nextStep, $hint['step']);
            $this->assertNothingPersonal($hint, 'la consigne '.$nextStep);
        }

        // Au repos, les coordonnées déjà collectées sont chiffrées : pas de clair en base avant la commande.
        $stored = (string) $this->em->getConnection()->fetchOne('SELECT checkout_data FROM cart WHERE id = ?', [self::CART_ID]);
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $stored);
        }

        $final = $this->api('POST', $this->checkout('message'), ['text' => self::EMAIL]);
        self::assertTrue($final['accepted']);
        self::assertSame('CONFIRMED', $final['step']);
        self::assertSame('[CUSTOMER_EMAIL]', $final['llm_message']);
        $order = $final['order'];
        self::assertIsArray($order);
        self::assertMatchesRegularExpression('/^AC-\d{8}-[0-9A-F]{6}$/', (string) $order['reference']);
        self::assertSame(13500, $order['total_cents']);
        foreach (['[CUSTOMER_NAME]', '[SHIPPING_ADDRESS]', '[CUSTOMER_PHONE]', '[CUSTOMER_EMAIL]'] as $token) {
            self::assertStringContainsString($token, (string) $final['instruction'], 'récapitulatif par jetons');
        }
        $this->assertNothingPersonal($final, 'la confirmation');

        // La commande finale contient toutes les vraies coordonnées.
        $this->em->clear();
        $orders = $this->em->getRepository(Order::class)->findAll();
        self::assertCount(1, $orders);
        self::assertSame(self::NAME, $orders[0]->fullName);
        self::assertSame(self::ADDRESS, $orders[0]->shippingAddress);
        self::assertSame('0612345678', $orders[0]->phone);
        self::assertSame(self::EMAIL, $orders[0]->email);
        self::assertSame(13500, $orders[0]->totalCents);
        self::assertSame('confirmed', $orders[0]->status);

        // Plus aucune donnée en clair ni chiffrée sur le panier, et la confirmation n'est annoncée qu'une fois.
        $cart = $this->em->find(Cart::class, Uuid::fromString(self::CART_ID));
        self::assertNotNull($cart);
        self::assertSame([], $cart->checkoutData);
        $confirmedHint = $this->api('POST', $this->checkout('hint'));
        self::assertFalse($confirmedHint['active']);
        self::assertSame('CONFIRMED', $confirmedHint['step']);
        $this->assertNothingPersonal($confirmedHint, 'la consigne de confirmation');
        $after = $this->api('POST', $this->checkout('hint'));
        self::assertFalse($after['active']);
        self::assertNull($after['step']);
    }

    public function testEachStepOnlyAcceptsItsTargetDataAndKeepsTheStateOtherwise(): void
    {
        $this->fillCart();
        $this->api('POST', $this->checkout('start'));

        // L'adresse tapée à l'étape « nom » est refusée : l'état ne bouge pas, rien de personnel ne sort.
        $refused = $this->api('POST', $this->checkout('message'), ['text' => self::ADDRESS]);
        self::assertResponseIsSuccessful();
        self::assertFalse($refused['accepted']);
        self::assertSame('AWAITING_FULL_NAME', $refused['step']);
        self::assertSame('[INVALID_INPUT]', $refused['llm_message']);
        self::assertStringContainsString('poliment', (string) $refused['instruction']);
        $this->assertNothingPersonal($refused, 'une saisie refusée');

        $this->api('POST', $this->checkout('message'), ['text' => self::NAME]);
        $wrongEmail = $this->api('POST', $this->checkout('message'), ['text' => self::EMAIL]);
        self::assertFalse($wrongEmail['accepted'], 'un email à l\'étape adresse est refusé');
        self::assertSame('AWAITING_ADDRESS', $wrongEmail['step']);

        $this->api('POST', $this->checkout('message'), ['text' => self::ADDRESS]);
        $badPhone = $this->api('POST', $this->checkout('message'), ['text' => '06 12 34']);
        self::assertFalse($badPhone['accepted']);
        self::assertSame('AWAITING_PHONE', $badPhone['step']);
        $this->assertNothingPersonal($badPhone, 'un téléphone invalide');

        $this->api('POST', $this->checkout('message'), ['text' => '+33 6 12 34 56 78']);
        $badEmail = $this->api('POST', $this->checkout('message'), ['text' => 'marie.dupont@']);
        self::assertFalse($badEmail['accepted']);
        self::assertSame('AWAITING_EMAIL', $badEmail['step']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM customer_order'), 'pas de commande tant que tout n\'est pas validé');
    }

    public function testCheckoutNeedsACartAndAnActiveCollection(): void
    {
        $this->api('POST', $this->checkout('start'));
        self::assertResponseStatusCodeSame(409);

        $this->api('POST', $this->checkout('message'), ['text' => self::NAME]);
        self::assertResponseStatusCodeSame(409, 'pas de collecte sans démarrage');

        $this->fillCart();
        $this->api('POST', $this->checkout('message'), ['text' => self::NAME]);
        self::assertResponseStatusCodeSame(409);

        $this->api('POST', $this->checkout('message'), ['text' => '']);
        self::assertResponseStatusCodeSame(422);
        $this->api('POST', $this->checkout('start'), auth: false);
        self::assertResponseStatusCodeSame(401);
    }

    public function testCancelDropsTheCollectedDataAndRestartBeginsAgain(): void
    {
        $this->fillCart();
        $this->api('POST', $this->checkout('start'));
        $this->api('POST', $this->checkout('message'), ['text' => self::NAME]);

        $this->api('POST', $this->checkout('cancel'));
        self::assertResponseIsSuccessful();
        self::assertFalse($this->api('POST', $this->checkout('hint'))['active']);
        $this->api('POST', $this->checkout('message'), ['text' => self::ADDRESS]);
        self::assertResponseStatusCodeSame(409);

        $restart = $this->api('POST', $this->checkout('start'));
        self::assertSame('AWAITING_FULL_NAME', $restart['step']);
        self::assertSame('[]', (string) $this->em->getConnection()->fetchOne('SELECT checkout_data FROM cart WHERE id = ?', [self::CART_ID]));
    }

    /**
     * @param list<string> $texts
     *
     * @return array<string, mixed> dernière réponse
     */
    private function say(array $texts): array
    {
        $turn = [];
        foreach ($texts as $text) {
            $turn = $this->api('POST', $this->checkout('message'), ['text' => $text]);
            self::assertResponseIsSuccessful();
            $this->assertNothingPersonal($turn, 'la réponse à « '.$text.' »');
        }

        return $turn;
    }

    public function testNaturalLanguageCorrectionDuringCollectionReturnsToTheCurrentStep(): void
    {
        $this->fillCart();
        $this->api('POST', $this->checkout('start'));
        $this->say([self::NAME, self::ADDRESS]);

        // Étape « téléphone » : le client réalise qu'il s'est trompé d'adresse.
        $edit = $this->say(["Oups, je me suis trompé d'adresse"]);
        self::assertFalse($edit['accepted']);
        self::assertSame('[EDIT_REQUEST]', $edit['llm_message'], 'le texte du client ne va jamais au LLM');
        self::assertSame('AWAITING_ADDRESS', $edit['step']);
        self::assertStringContainsString('adresse postale', (string) $edit['instruction']);

        $fixed = $this->say(['3 avenue Foch 75116 Paris']);
        self::assertTrue($fixed['accepted']);
        self::assertSame('[SHIPPING_ADDRESS]', $fixed['llm_message']);
        self::assertSame('AWAITING_PHONE', $fixed['step'], 'on reprend là où on en était');
        self::assertStringContainsString('reprends où nous en étions', (string) $fixed['instruction']);

        $this->say([self::PHONE]);
        $this->say([self::EMAIL]);
        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findAll()[0];
        self::assertSame('3 avenue Foch 75116 Paris', $order->shippingAddress, 'la commande porte l\'adresse corrigée');
        self::assertSame(self::NAME, $order->fullName);
    }

    public function testACorrectionWithoutTheFieldAsksWhichOneAndAcceptsTheChoice(): void
    {
        $this->fillCart();
        $this->api('POST', $this->checkout('start'));
        $this->say([self::NAME, self::ADDRESS]);

        $ask = $this->say(['je me suis trompé']);
        self::assertSame('[EDIT_REQUEST]', $ask['llm_message']);
        self::assertStringContainsString('quelle information', (string) $ask['instruction']);
        self::assertSame('AWAITING_PHONE', $ask['step']);

        $chosen = $this->say(['le nom']);
        self::assertSame('AWAITING_FULL_NAME', $chosen['step']);
        $this->say(['Marie-Claire Dupont']);
        self::assertSame('AWAITING_PHONE', $this->api('POST', $this->checkout('hint'))['step']);

        // Renoncer à corriger ramène à la question en cours.
        $this->say(['une erreur']);
        $dropped = $this->say(['non, rien']);
        self::assertStringContainsString('renonce', (string) $dropped['instruction']);
        $valid = $this->say([self::PHONE]);
        self::assertTrue($valid['accepted']);
        self::assertSame('AWAITING_EMAIL', $valid['step']);
    }

    public function testCorrectingSomethingNotGivenYetAndAddressesWithKeywordsAreHandled(): void
    {
        $this->fillCart();
        $this->api('POST', $this->checkout('start'));

        $early = $this->say(['je me suis trompé de numéro']);
        self::assertSame('AWAITING_FULL_NAME', $early['step']);
        self::assertStringContainsString('pas encore', (string) $early['instruction']);

        $this->say([self::NAME]);
        $keyword = $this->say(['3 rue du Changement 75011 Paris']);
        self::assertTrue($keyword['accepted'], 'une adresse valide n\'est pas une demande de correction');
        self::assertSame('AWAITING_PHONE', $keyword['step']);
    }

    public function testTheOrderCanBeCorrectedAfterItWasCreated(): void
    {
        $this->fillCart();
        $this->api('POST', $this->checkout('start'));
        $this->say([self::NAME, self::ADDRESS, self::PHONE, self::EMAIL]);
        $this->api('POST', $this->checkout('hint')); // la confirmation est annoncée au LLM

        $reopen = $this->api('POST', $this->checkout('edit'), ['field' => 'address']);
        self::assertResponseIsSuccessful();
        self::assertSame('AWAITING_ADDRESS', $reopen['step']);
        self::assertStringContainsString('adresse', (string) $reopen['instruction']);
        $this->assertNothingPersonal($reopen, 'la réouverture');

        $updated = $this->say(['8 quai Voltaire 75007 Paris']);
        self::assertTrue($updated['accepted']);
        self::assertSame('CONFIRMED', $updated['step']);
        self::assertNull($updated['order'], 'pas de nouvelle commande');
        self::assertStringContainsString('mise à jour', (string) $updated['instruction']);

        $this->em->clear();
        $orders = $this->em->getRepository(Order::class)->findAll();
        self::assertCount(1, $orders);
        self::assertSame('8 quai Voltaire 75007 Paris', $orders[0]->shippingAddress);
        self::assertSame(self::NAME, $orders[0]->fullName);
        self::assertSame(self::EMAIL, $orders[0]->email);
    }

    public function testEditNeedsACheckoutOrAnOrderAndAKnownField(): void
    {
        $this->api('POST', $this->checkout('edit'), ['field' => 'address']);
        self::assertResponseStatusCodeSame(409);

        $this->fillCart();
        $this->api('POST', $this->checkout('edit'), ['field' => 'address']);
        self::assertResponseStatusCodeSame(409, 'ni collecte ni commande');

        $this->api('POST', $this->checkout('edit'), ['field' => 'iban']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testOrderingAgainOnTheSameCartReplacesThePreviousOrder(): void
    {
        $this->fillCart();
        $this->api('POST', $this->checkout('start'));
        $this->say([self::NAME, self::ADDRESS, self::PHONE, self::EMAIL]);

        $this->api('POST', $this->checkout('start'));
        $this->say(['Paul Martin', '5 place Bellecour 69002 Lyon', '04 78 00 00 00', 'paul.martin@example.org']);

        $this->em->clear();
        $orders = $this->em->getRepository(Order::class)->findAll();
        self::assertCount(1, $orders);
        self::assertSame('Paul Martin', $orders[0]->fullName);
    }
}

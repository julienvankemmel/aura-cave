<?php

declare(strict_types=1);

namespace App\Checkout;

use App\Crypto\ZeroPiiCryptoService;
use App\Entity\Cart;
use App\Entity\Order;
use App\Enum\CheckoutStepEnum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Machine à états du tunnel de commande conversationnel : une donnée par message, validée puis aussitôt pseudonymisée.
 *
 *  - la donnée brute est chiffrée au repos (crypto_box_seal) dans le panier, jamais conservée en clair avant la commande ;
 *  - le LLM ne reçoit que le jeton d'anonymisation ([CUSTOMER_NAME]…) et une consigne sur la prochaine étape ;
 *  - à `CONFIRMED`, les vraies coordonnées sont réinjectées dans l'entité `Order` ;
 *  - le client peut corriger un champ en langage naturel (« je me suis trompé d'adresse ») avant comme après la commande.
 */
final readonly class ConversationCheckoutManager
{
    private const LABELS = [
        'full_name' => 'Le nom et le prénom',
        'address' => "L'adresse de livraison",
        'phone' => 'Le numéro de téléphone',
        'email' => "L'adresse email",
    ];

    public function __construct(private EntityManagerInterface $em, private ZeroPiiCryptoService $crypto)
    {
    }

    public function start(Cart $cart): CheckoutTurn
    {
        $step = CheckoutStepEnum::AwaitingFullName;
        $instruction = \sprintf(
            'Le tunnel de commande démarre. Demande au client, de façon naturelle et chaleureuse, quelque chose comme : « %s » Ne demande rien d\'autre et n\'invente aucune donnée.',
            $step->question(),
        );
        $cart->startCheckout($instruction);
        $this->em->flush();

        return new CheckoutTurn(true, $step, '', $instruction);
    }

    /**
     * Le client veut corriger une information (demande venue du chat classique, hors collecte, ou de la collecte).
     *
     * @param string $field name | address | phone | email
     *
     * @throws NothingToEditException ni collecte en cours ni commande à corriger
     */
    public function edit(Cart $cart, string $field): CheckoutTurn
    {
        $target = CheckoutStepEnum::forField($field);
        $current = $cart->checkoutStep;
        if (null === $target) {
            throw new NothingToEditException('Unknown field.');
        }

        if (null !== $current && $current->isCollecting()) {
            return $this->reopen($cart, $target, $current);
        }
        if (null === $this->orderOf($cart)) {
            throw new NothingToEditException('No checkout or order to edit.');
        }

        // Commande déjà créée : on redemande uniquement le champ, puis la commande est mise à jour.
        $instruction = $this->editInstruction($target);
        $cart->reopenCheckout($target, CheckoutStepEnum::Confirmed, $instruction);
        $this->em->flush();

        return new CheckoutTurn(true, $target, CheckoutTurn::EDIT_TOKEN, $instruction);
    }

    /**
     * @throws CheckoutNotStartedException aucun checkout en cours de collecte pour ce panier
     */
    public function handle(Cart $cart, string $rawText): CheckoutTurn
    {
        $step = $cart->checkoutStep;
        if (null === $step || !$step->isCollecting()) {
            throw new CheckoutNotStartedException('No checkout is collecting data for this cart.');
        }

        if ($cart->checkoutEditing) {
            return $this->chooseFieldToEdit($cart, $step, $rawText);
        }

        $value = FieldValidator::normalize($step, $rawText);
        // Une adresse valide l'emporte (« 3 rue du Changement… ») ; sinon, « je me suis trompé… » est une demande de correction.
        $isValidAddress = CheckoutStepEnum::AwaitingAddress === $step && null !== $value;
        if (!$isValidAddress && EditIntent::isCorrection($rawText)) {
            $target = EditIntent::field($rawText);

            return null === $target ? $this->askWhichField($cart, $step) : $this->reopen($cart, $target, $this->resumePoint($cart, $step));
        }

        if (null === $value) {
            return $this->invalid($cart, $step);
        }

        return $this->accept($cart, $step, $value);
    }

    /**
     * Consigne à transmettre au LLM avec le prochain message. Une commande confirmée n'est annoncée qu'une fois.
     *
     * @return array{active: bool, step: string|null, instruction: string|null}
     */
    public function hint(Cart $cart): array
    {
        $step = $cart->checkoutStep;
        if (null === $step) {
            return ['active' => false, 'step' => null, 'instruction' => null];
        }

        $result = ['active' => $step->isCollecting(), 'step' => $step->value, 'instruction' => $cart->checkoutInstruction];
        if (!$step->isCollecting()) {
            $cart->endCheckout();
            $this->em->flush();
        }

        return $result;
    }

    public function cancel(Cart $cart): void
    {
        $cart->endCheckout();
        $this->em->flush();
    }

    private function invalid(Cart $cart, CheckoutStepEnum $step): CheckoutTurn
    {
        $instruction = \sprintf(
            'La dernière réponse du client (%s) n\'a pas pu être validée : on attend %s. Reste sur cette étape : explique poliment ce qu\'il faut fournir, sans citer ni reformuler sa réponse, puis redemande : « %s » Ne demande rien d\'autre.',
            CheckoutTurn::INVALID_TOKEN,
            $step->expected(),
            $step->question(),
        );
        $cart->setCheckoutInstruction($instruction);
        $this->em->flush();

        return new CheckoutTurn(false, $step, CheckoutTurn::INVALID_TOKEN, $instruction);
    }

    private function accept(Cart $cart, CheckoutStepEnum $step, string $value): CheckoutTurn
    {
        /** @var string $field */
        $field = $step->field();
        /** @var string $placeholder */
        $placeholder = $step->placeholder();
        $resume = $cart->checkoutResumeStep;

        // Correction d'une commande déjà créée : on met la commande à jour, sans repasser par les autres champs.
        if (CheckoutStepEnum::Confirmed === $resume) {
            return $this->updateOrder($cart, $field, $value, $placeholder);
        }

        $next = $resume ?? $step->next();
        if (CheckoutStepEnum::Confirmed === $next) {
            return $this->confirm($cart, $field, $value, $placeholder);
        }

        $instruction = \sprintf(
            '%s a été validé(e) sous %s. Remercie brièvement puis %s, de façon naturelle, quelque chose comme : « %s » Ne répète jamais de donnée personnelle (utilise uniquement les jetons entre crochets) et ne demande rien d\'autre.',
            self::LABELS[$field],
            $placeholder,
            null === $resume ? 'demande maintenant' : 'reprends où nous en étions et redemande',
            $next->question(),
        );
        $cart->recordCheckoutField($field, $this->crypto->seal($value), $next, $instruction);
        $this->em->flush();

        return new CheckoutTurn(true, $next, $placeholder, $instruction);
    }

    /** Étape à reprendre après une correction : celle où l'on se trouvait (ou celle déjà mémorisée). */
    private function resumePoint(Cart $cart, CheckoutStepEnum $current): CheckoutStepEnum
    {
        return $cart->checkoutResumeStep ?? $current;
    }

    private function reopen(Cart $cart, CheckoutStepEnum $target, CheckoutStepEnum $current): CheckoutTurn
    {
        $resume = $this->resumePoint($cart, $current);
        // On ne peut corriger que ce qui a déjà été donné : pour le reste, on poursuit la question en cours.
        if ($target->rank() >= $resume->rank() && CheckoutStepEnum::Confirmed !== $resume) {
            $instruction = \sprintf(
                'Le client voulait corriger %s mais ne l\'a pas encore donné(e) : dis-lui simplement que nous y arrivons, puis redemande : « %s » Ne demande rien d\'autre.',
                $target->subject(),
                $resume->question(),
            );
            $cart->dismissEdit($instruction);
            $this->em->flush();

            return new CheckoutTurn(false, $resume, CheckoutTurn::EDIT_TOKEN, $instruction);
        }

        $instruction = $this->editInstruction($target);
        $cart->reopenCheckout($target, $resume, $instruction);
        $this->em->flush();

        return new CheckoutTurn(false, $target, CheckoutTurn::EDIT_TOKEN, $instruction);
    }

    private function editInstruction(CheckoutStepEnum $target): string
    {
        return \sprintf(
            'Le client souhaite corriger %s. Confirme-le brièvement (sans rien répéter de ce qu\'il avait donné) puis demande de nouveau, de façon naturelle, quelque chose comme : « %s » Ne demande rien d\'autre.',
            $target->subject(),
            $target->question(),
        );
    }

    private function askWhichField(Cart $cart, CheckoutStepEnum $step): CheckoutTurn
    {
        $instruction = 'Le client souhaite corriger une information mais n\'a pas dit laquelle. Demande-lui, en une question, quelque chose comme : « Pas de souci : quelle information souhaitez-vous corriger : le nom, l\'adresse, le téléphone ou l\'email ? » Ne demande rien d\'autre.';
        $cart->awaitFieldChoice($instruction);
        $this->em->flush();

        return new CheckoutTurn(false, $step, CheckoutTurn::EDIT_TOKEN, $instruction);
    }

    private function chooseFieldToEdit(Cart $cart, CheckoutStepEnum $step, string $rawText): CheckoutTurn
    {
        $target = EditIntent::field($rawText);
        if (null !== $target) {
            return $this->reopen($cart, $target, $this->resumePoint($cart, $step));
        }

        $resume = $this->resumePoint($cart, $step);
        if (EditIntent::givesUp($rawText)) {
            $instruction = \sprintf('Le client renonce à corriger quoi que ce soit. Reprends simplement : « %s » Ne demande rien d\'autre.', $resume->question());
            $cart->dismissEdit($instruction);
        } else {
            $instruction = 'Le client n\'a pas précisé le champ à corriger. Redemande en une question : « Quelle information souhaitez-vous corriger : le nom, l\'adresse, le téléphone ou l\'email ? » Ne demande rien d\'autre.';
            $cart->awaitFieldChoice($instruction);
        }
        $this->em->flush();

        return new CheckoutTurn(false, $step, CheckoutTurn::EDIT_TOKEN, $instruction);
    }

    private function orderOf(Cart $cart): ?Order
    {
        return $this->em->getRepository(Order::class)->findOneBy(['cart' => $cart]);
    }

    private function updateOrder(Cart $cart, string $field, string $value, string $placeholder): CheckoutTurn
    {
        $order = $this->orderOf($cart);
        if (null === $order) {
            throw new NothingToEditException('No order to update.');
        }
        /** @var 'full_name'|'address'|'phone'|'email' $field */
        $order->correct($field, $value);
        $instruction = \sprintf(
            'La commande %s a bien été mise à jour : %s est enregistré(e). Confirme-le brièvement avec le jeton %s, sans donnée personnelle, et n\'ajoute rien d\'autre.',
            $order->reference,
            self::LABELS[$field],
            $placeholder,
        );
        $cart->confirmCheckout($instruction);
        $this->em->flush();

        return new CheckoutTurn(true, CheckoutStepEnum::Confirmed, $placeholder, $instruction);
    }

    private function confirm(Cart $cart, string $lastField, string $lastValue, string $lastPlaceholder): CheckoutTurn
    {
        $data = [];
        foreach ($cart->checkoutData as $field => $sealed) {
            $data[$field] = $this->crypto->decrypt($sealed);
        }
        $data[$lastField] = $lastValue;

        $order = new Order($cart, $cart->totalCents, $data['full_name'], $data['address'], $data['phone'], $data['email']);
        $instruction = \sprintf(
            'La commande %s est confirmée (total %s €). Remercie chaleureusement le client et fais un récapitulatif avec les jetons %s, %s, %s et %s (jamais de vraies données personnelles) et le total. N\'ajoute rien d\'autre.',
            $order->reference,
            number_format($order->totalCents / 100, 2, ',', ' '),
            '[CUSTOMER_NAME]',
            '[SHIPPING_ADDRESS]',
            '[CUSTOMER_PHONE]',
            '[CUSTOMER_EMAIL]',
        );
        // Un panier porte une seule commande : repasser commande sur le même panier remplace la précédente.
        $previous = $this->orderOf($cart);
        if (null !== $previous) {
            $this->em->remove($previous);
            $this->em->flush();
        }
        $this->em->persist($order);
        $cart->confirmCheckout($instruction);
        $this->em->flush();

        return new CheckoutTurn(true, CheckoutStepEnum::Confirmed, $lastPlaceholder, $instruction, $order->reference, $order->totalCents);
    }
}

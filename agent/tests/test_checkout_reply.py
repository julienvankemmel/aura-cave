import pytest

from aura_agent.core.checkout_reply import CONFIRMED_FALLBACK, fallback_reply, vet_checkout_reply

ASK_EMAIL = (
    "Le numéro de téléphone a été validé sous [CUSTOMER_PHONE]. Demande maintenant, de façon naturelle, quelque chose "
    "comme : « Et enfin, quelle est votre adresse email pour le suivi ? » Ne demande rien d'autre."
)
RETRY = (
    "La dernière réponse du client ([INVALID_INPUT]) n'a pas pu être validée : on attend une adresse email valide. "
    "Redemande : « Et enfin, quelle est votre adresse email pour le suivi ? »"
)
CONFIRMED = "La commande AC-20261110-ABC123 est confirmée (total 120,00 €). Remercie avec les jetons [CUSTOMER_NAME]."


def test_a_complete_single_question_is_kept() -> None:
    text = "Merci pour votre numéro. Et enfin, quelle est votre adresse email pour le suivi ?"

    assert vet_checkout_reply(f"  {text} ", ASK_EMAIL) == text


@pytest.mark.parametrize(
    "broken",
    [
        "Merci pour le numéro. Enfin, pourriez‑c‑",  # phrase coupée en plein vol
        "",
        "Merci !",
        "Merci. Quelle est votre adresse email ? Et votre adresse postale ?",  # deux questions
        "Parfait, votre numéro est enregistré.",  # aucune question
        "Appelez-moi au 06 12 34 56 78 ou écrivez à jeanne@example.test, quelle est votre adresse email ?",  # PII
    ],
)
def test_broken_replies_fall_back_to_the_scripted_question(broken: str) -> None:
    assert vet_checkout_reply(broken, ASK_EMAIL) == "Merci ! Et enfin, quelle est votre adresse email pour le suivi ?"


def test_an_invalid_answer_gets_a_polite_retry() -> None:
    assert (
        fallback_reply(RETRY)
        == "Je n'ai pas pu valider cette réponse. Et enfin, quelle est votre adresse email pour le suivi ?"
    )


def test_the_confirmation_must_be_complete_and_otherwise_uses_the_token_summary() -> None:
    good = "Merci [CUSTOMER_NAME], votre commande AC-20261110-ABC123 est confirmée."

    assert vet_checkout_reply(good, CONFIRMED) == good
    assert vet_checkout_reply("Merci [CUSTOMER_NAME], votre commande est con", CONFIRMED) == CONFIRMED_FALLBACK
    assert "06 12" not in vet_checkout_reply("Merci, au 06 12 34 56 78 : votre commande est confirmée.", CONFIRMED)


def test_an_order_update_is_confirmed_with_a_complete_sentence_or_the_scripted_one() -> None:
    from aura_agent.core.checkout_reply import UPDATED_FALLBACK

    updated = "La commande AC-1 a bien été mise à jour : L'adresse de livraison est enregistré(e). Confirme avec [SHIPPING_ADDRESS]."

    assert (
        vet_checkout_reply("C'est corrigé, votre adresse est à jour.", updated)
        == "C'est corrigé, votre adresse est à jour."
    )
    assert vet_checkout_reply("C'est corr", updated) == UPDATED_FALLBACK


def test_a_correction_request_is_acknowledged_before_the_question() -> None:
    hint = "Le client souhaite corriger son adresse de livraison. Confirme puis demande : « Quelle est votre adresse postale de livraison ? »"

    assert fallback_reply(hint) == "Bien sûr. Quelle est votre adresse postale de livraison ?"

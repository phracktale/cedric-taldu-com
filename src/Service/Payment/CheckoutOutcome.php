<?php

declare(strict_types=1);

namespace App\Service\Payment;

/**
 * Issue d'une tentative de commande (03-boutique §3).
 *
 * Chaque cas d'echec dit CE QUI a change, parce que le visiteur doit
 * comprendre pourquoi il revient au panier : « la transaction est annulee et
 * l'utilisateur revient au panier avec un message clair ».
 */
enum CheckoutOutcome
{
    /** Commande creee, session ouverte : rediriger en 303 vers la passerelle. */
    case Redirect;

    /** Le panier a change depuis son affichage : le montrer avec ses messages. */
    case CartChanged;

    /** Plus rien a commander. */
    case EmptyCart;

    /** Aucune tranche ne couvre ce colis : proposer le formulaire de contact. */
    case ShippingOnRequest;
}

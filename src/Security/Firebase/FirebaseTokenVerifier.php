<?php

namespace App\Security\Firebase;

/**
 * Abstraction volontaire au-dessus du SDK Firebase : elle permet de remplacer
 * la vérification par un double en test, et donc de garder la suite hors réseau
 * (la vérification réelle télécharge les clés publiques Google).
 */
interface FirebaseTokenVerifier
{
    /**
     * @throws InvalidFirebaseTokenException si le token est absent, malformé,
     *                                       expiré, ou si sa signature est invalide
     */
    public function verify(string $idToken): VerifiedFirebaseToken;
}

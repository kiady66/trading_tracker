<?php

namespace App\Security\Firebase;

/**
 * Les seules données d'un ID token Firebase dont l'application a besoin,
 * une fois la signature vérifiée.
 */
final class VerifiedFirebaseToken
{
    public function __construct(
        public readonly string $uid,
        public readonly ?string $email,
        public readonly bool $emailVerified,
    ) {
    }
}

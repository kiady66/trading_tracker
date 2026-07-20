<?php

namespace App\Security\Firebase;

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Exception\Auth\RevokedIdToken;

final class KreaitFirebaseTokenVerifier implements FirebaseTokenVerifier
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function verify(string $idToken): VerifiedFirebaseToken
    {
        if ($idToken === '') {
            throw new InvalidFirebaseTokenException('Token absent.');
        }

        try {
            // Vérifie signature, émetteur, audience et expiration. C'est l'unique
            // barrière : sans cet appel, le token n'est que du texte fourni par le client.
            $token = $this->auth->verifyIdToken($idToken);
        } catch (FailedToVerifyToken|RevokedIdToken $e) {
            throw new InvalidFirebaseTokenException($e->getMessage(), previous: $e);
        }

        $claims = $token->claims();
        $uid = $claims->get('sub');

        if (!is_string($uid) || $uid === '') {
            throw new InvalidFirebaseTokenException('Token sans identifiant utilisateur.');
        }

        $email = $claims->get('email');
        $emailVerified = $claims->get('email_verified');

        return new VerifiedFirebaseToken(
            uid: $uid,
            email: is_string($email) && $email !== '' ? $email : null,
            emailVerified: $emailVerified === true,
        );
    }
}

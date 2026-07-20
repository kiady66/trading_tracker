<?php

namespace App\Security\Firebase;

use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Factory;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Construit le service Auth de kreait à partir de la configuration d'environnement.
 *
 * Les credentials sont acceptés sous deux formes, pour que le même code fonctionne
 * en local (fichier hors dépôt) et sur un hébergeur au système de fichiers éphémère
 * type Railway (contenu JSON encodé en base64 dans une variable d'environnement).
 */
final class FirebaseAuthFactory
{
    public function __construct(
        private readonly string $credentials,
        private readonly CacheItemPoolInterface $verifierCache,
    ) {
    }

    public function create(): Auth
    {
        if (trim($this->credentials) === '') {
            throw new \LogicException(
                'FIREBASE_CREDENTIALS est vide : renseignez le chemin du fichier de service account '
                .'ou son contenu JSON encodé en base64.'
            );
        }

        return (new Factory())
            ->withServiceAccount($this->resolveServiceAccount())
            // Met en cache les clés publiques Google pour ne pas les retélécharger à chaque login.
            ->withVerifierCache($this->verifierCache)
            ->createAuth();
    }

    /**
     * @return string chemin d'un fichier existant, ou contenu JSON
     */
    private function resolveServiceAccount(): string
    {
        $value = trim($this->credentials);

        if (is_file($value)) {
            return $value;
        }

        // Un JSON brut passé directement dans la variable d'environnement.
        if (str_starts_with($value, '{')) {
            return $value;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false || !str_starts_with(ltrim($decoded), '{')) {
            throw new \LogicException(
                'FIREBASE_CREDENTIALS ne correspond ni à un fichier existant, ni à du JSON, '
                .'ni à du JSON encodé en base64.'
            );
        }

        return $decoded;
    }
}

<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Firebase\FirebaseTokenVerifier;
use App\Security\Firebase\InvalidFirebaseTokenException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class FirebaseAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly FirebaseTokenVerifier $verifier,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST')
            && $request->attributes->get('_route') === 'app_auth_firebase';
    }

    public function authenticate(Request $request): Passport
    {
        $idToken = $this->extractIdToken($request);

        try {
            $token = $this->verifier->verify($idToken);
        } catch (InvalidFirebaseTokenException) {
            throw new CustomUserMessageAuthenticationException('Authentification Firebase invalide.');
        }

        if ($token->email === null) {
            throw new CustomUserMessageAuthenticationException(
                "Ce compte ne fournit pas d'adresse e-mail, nécessaire pour utiliser l'application."
            );
        }

        // Google renseigne email_verified à true pour un compte standard. Le contrôle
        // protège des providers futurs plus laxistes : sans lui, un compte dont l'e-mail
        // n'est pas prouvé pourrait être rattaché à un compte existant portant cet e-mail.
        if (!$token->emailVerified) {
            throw new CustomUserMessageAuthenticationException(
                "L'adresse e-mail de ce compte n'est pas vérifiée."
            );
        }

        return new SelfValidatingPassport(
            new UserBadge($token->uid, fn (): User => $this->resolveUser($token->uid, $token->email))
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        // Le pseudo est obligatoire et définitif : on le fait choisir avant tout le reste.
        if ($user instanceof User && $user->getDisplayName() === null) {
            $this->removeTargetPath($request->getSession(), $firewallName);

            return new JsonResponse(['redirectTo' => $this->urlGenerator->generate('app_onboarding')]);
        }

        $target = $this->getTargetPath($request->getSession(), $firewallName)
            ?? $this->urlGenerator->generate('app_trade_index');

        $this->removeTargetPath($request->getSession(), $firewallName);

        return new JsonResponse(['redirectTo' => $target]);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $message = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessage()
            : 'La connexion a échoué.';

        return new JsonResponse(['error' => $message], Response::HTTP_UNAUTHORIZED);
    }

    private function extractIdToken(Request $request): string
    {
        // Décodage explicite plutôt que Request::toArray() : ce dernier lève une
        // exception convertie en 400 par le noyau, court-circuitant la réponse
        // 401 en JSON que le front attend.
        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload)) {
            throw new CustomUserMessageAuthenticationException('Requête invalide.');
        }

        $idToken = $payload['idToken'] ?? null;

        if (!is_string($idToken) || $idToken === '') {
            throw new CustomUserMessageAuthenticationException('Requête invalide.');
        }

        return $idToken;
    }

    /**
     * Résolution en trois temps : compte déjà lié, puis rattachement par e-mail,
     * puis création. L'UID Firebase est la clé d'identité — il ne change jamais,
     * contrairement à l'e-mail.
     */
    private function resolveUser(string $uid, string $email): User
    {
        $user = $this->users->findOneBy(['firebaseUid' => $uid]);

        if ($user !== null) {
            return $user;
        }

        $user = $this->users->findOneBy(['email' => $email]);

        if ($user !== null) {
            // Rattachement d'un compte préexistant. L'e-mail en base n'est
            // volontairement jamais resynchronisé par la suite.
            $user->setFirebaseUid($uid);
            $this->entityManager->flush();

            return $user;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirebaseUid($uid);
        $user->setRoles([User::ROLE_TRADER]);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

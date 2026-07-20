<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Force un utilisateur sans pseudo à passer par l'onboarding.
 *
 * Le pseudo est obligatoire pour le profil public et définitif une fois choisi ;
 * le collecter au premier login évite des comptes durablement incomplets.
 */
class OnboardingSubscriber implements EventSubscriberInterface
{
    /**
     * Routes qui ne doivent JAMAIS déclencher la redirection, sous peine de
     * boucle infinie (l'onboarding lui-même) ou d'utilisateur piégé sans
     * possibilité de se déconnecter.
     */
    private const ALLOWED_ROUTES = [
        'app_onboarding',
        'app_logout',
        'app_login',
        'app_auth_firebase',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 5]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Les sous-requêtes (render() dans un template, ESI) ne doivent pas
        // pouvoir remplacer la réponse principale par une redirection.
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Le firewall api est sans session et sert des clients non-navigateur :
        // une redirection HTML y serait incompréhensible. Le profiler et les
        // assets de développement n'ont rien à voir avec l'onboarding non plus.
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/_')) {
            return;
        }

        if (in_array($request->attributes->get('_route'), self::ALLOWED_ROUTES, true)) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User || $user->getDisplayName() !== null) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_onboarding')));
    }
}

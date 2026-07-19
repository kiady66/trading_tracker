<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose le compteur de messages non lus pour le badge « Chat » de la nav admin.
 * Runtime lazy : le repository n'est instancié que si la fonction est appelée.
 */
class ChatExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('chat_admin_unread_count', [ChatExtensionRuntime::class, 'adminUnreadCount']),
        ];
    }
}

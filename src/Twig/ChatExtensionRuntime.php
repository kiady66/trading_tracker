<?php

namespace App\Twig;

use App\Repository\ChatMessageRepository;
use Twig\Extension\RuntimeExtensionInterface;

class ChatExtensionRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly ChatMessageRepository $chatMessageRepository,
    ) {
    }

    public function adminUnreadCount(): int
    {
        return $this->chatMessageRepository->countUnreadForAdmin();
    }
}

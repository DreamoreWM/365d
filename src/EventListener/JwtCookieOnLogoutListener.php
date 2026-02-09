<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Http\Event\LogoutEvent;

#[AsEventListener(event: LogoutEvent::class)]
class JwtCookieOnLogoutListener
{
    public function __invoke(LogoutEvent $event): void
    {
        $response = $event->getResponse();
        if ($response === null) {
            return;
        }

        $response->headers->clearCookie('BEARER', '/');
    }
}

<?php

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
class JwtCookieOnLoginListener
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private int $tokenTtl = 3600,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        // Only handle form_login on the main firewall, not API login
        if ($event->getFirewallName() !== 'main') {
            return;
        }

        $user = $event->getUser();
        $token = $this->jwtManager->create($user);

        $response = $event->getResponse();
        if ($response === null) {
            return;
        }

        $response->headers->setCookie(
            Cookie::create('BEARER')
                ->withValue($token)
                ->withExpires(time() + $this->tokenTtl)
                ->withPath('/')
                ->withHttpOnly(true)
                ->withSecure('auto')
                ->withSameSite('lax')
        );
    }
}

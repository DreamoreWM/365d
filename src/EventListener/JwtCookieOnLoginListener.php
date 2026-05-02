<?php

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
class JwtCookieOnLoginListener
{
    private const REMEMBER_ME_TTL = 30 * 24 * 3600; // 30 days

    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private JWTEncoderInterface $jwtEncoder,
        private int $tokenTtl = 43200,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        // Only handle form_login on the main firewall, not API login
        if ($event->getFirewallName() !== 'main') {
            return;
        }

        $user = $event->getUser();
        $rememberMe = $event->getRequest()->request->has('_remember_me');
        $ttl = $rememberMe ? self::REMEMBER_ME_TTL : $this->tokenTtl;

        if ($rememberMe) {
            // Create token with extended expiration
            $payload = $this->jwtManager->parse($this->jwtManager->create($user));
            $payload['exp'] = time() + $ttl;
            $token = $this->jwtEncoder->encode($payload);
        } else {
            $token = $this->jwtManager->create($user);
        }

        $response = $event->getResponse();
        if ($response === null) {
            return;
        }

        $response->headers->setCookie(
            Cookie::create('BEARER')
                ->withValue($token)
                ->withExpires(time() + $ttl)
                ->withPath('/')
                ->withHttpOnly(true)
                ->withSecure('auto')
                ->withSameSite('lax')
        );
    }
}

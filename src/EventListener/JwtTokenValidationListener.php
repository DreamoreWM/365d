<?php

namespace App\EventListener;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_authenticated')]
class JwtTokenValidationListener
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(JWTAuthenticatedEvent $event): void
    {
        $payload = $event->getPayload();
        $user = $event->getToken()->getUser();

        if (!$user instanceof User) {
            return;
        }

        $tokenValidAfter = $user->getTokenValidAfter();
        if ($tokenValidAfter === null) {
            return;
        }

        $issuedAt = $payload['iat'] ?? null;
        if ($issuedAt === null) {
            throw new CustomUserMessageAuthenticationException('Token invalide.');
        }

        $issuedAtDate = (new \DateTimeImmutable())->setTimestamp($issuedAt);

        if ($issuedAtDate < $tokenValidAfter) {
            throw new CustomUserMessageAuthenticationException('Token révoqué. Veuillez vous reconnecter.');
        }
    }
}

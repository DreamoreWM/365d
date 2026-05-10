<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::RESPONSE)]
class SecurityHeadersListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-XSS-Protection', '1; mode=block');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        // HSTS : forcer HTTPS pendant 1 an (à n'activer qu'en prod derrière TLS)
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Content-Security-Policy : adapté à l'usage FullCalendar + CDN
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy',
                "default-src 'self'; " .
                "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
                "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
                "font-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; " .
                "img-src 'self' data: https:; " .
                "connect-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; " .
                "frame-ancestors 'self';"
            );
        }
    }
}

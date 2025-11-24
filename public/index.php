<?php

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

// Configuration pour Scalingo
Request::setTrustedProxies(
    // Accepter tous les proxies (Scalingo)
    ['127.0.0.1', '10.0.0.0/8', 'REMOTE_ADDR'],
    // Faire confiance à tous les headers X-Forwarded
    Request::HEADER_X_FORWARDED_FOR | 
    Request::HEADER_X_FORWARDED_HOST | 
    Request::HEADER_X_FORWARDED_PORT | 
    Request::HEADER_X_FORWARDED_PROTO
);

return function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
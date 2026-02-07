<?php

namespace App\Serializer;

use ApiPlatform\State\SerializerContextBuilderInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;

class AdminGroupsContextBuilder implements SerializerContextBuilderInterface
{
    public function __construct(
        private SerializerContextBuilderInterface $decorated,
        private Security $security,
    ) {
    }

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $context = $this->decorated->createFromRequest($request, $normalization, $extractedAttributes);

        if ($this->security->isGranted('ROLE_ADMIN')) {
            $context['groups'] = $context['groups'] ?? [];

            if ($normalization) {
                $context['groups'][] = 'admin:read';
            } else {
                $context['groups'][] = 'admin:write';
            }
        }

        return $context;
    }
}

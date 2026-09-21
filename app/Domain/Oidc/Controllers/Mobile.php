<?php

namespace Leantime\Domain\Oidc\Controllers;

use Leantime\Core\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility tombstone for the removed upstream mobile OIDC bridge.
 *
 * Julianna 1.0 accepts only its local password-plus-MFA identity flow. Keeping
 * this controller as a fail-closed tombstone prevents an old bookmarked route
 * or cached client from ever minting a token through legacy code.
 */
class Mobile extends Controller
{
    public function exchange(array $params): Response
    {
        return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
    }
}

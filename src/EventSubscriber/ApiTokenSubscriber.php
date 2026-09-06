<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ApiTokenSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly string $expectedToken)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 32]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        if ('' === $this->expectedToken) {
            return;
        }
        $auth = (string) $request->headers->get('Authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/', $auth, $matches) || !hash_equals($this->expectedToken, $matches[1])) {
            $event->setResponse(new JsonResponse(['response' => null, 'message' => 'Invalid or missing token'], 401));
        }
    }
}

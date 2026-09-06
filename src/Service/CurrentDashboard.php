<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Dashboard;
use App\Repository\DashboardRepository;
use Symfony\Component\HttpFoundation\RequestStack;

final class CurrentDashboard
{
    public function __construct(
        private readonly RequestStack $stack,
        private readonly DashboardRepository $repository,
    ) {
    }

    public function tryGet(): ?Dashboard
    {
        try {
            $session = $this->stack->getSession();
            $id = $session->get('current_dashboard_id');
            if (\is_int($id)) {
                $dashboard = $this->repository->find($id);
                if (null !== $dashboard) {
                    return $dashboard;
                }
            }
        } catch (\Throwable) {
            // No session available (e.g. CLI context) — fall through.
        }

        return $this->repository->findOneBy([], ['id' => 'ASC']);
    }

    public function get(): Dashboard
    {
        return $this->tryGet() ?? throw new \RuntimeException('No dashboard exists');
    }

    public function switch(int $id): void
    {
        if (null !== $this->repository->find($id)) {
            $this->stack->getSession()->set('current_dashboard_id', $id);
        }
    }
}

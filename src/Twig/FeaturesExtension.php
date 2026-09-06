<?php

declare(strict_types=1);

namespace App\Twig;

use App\Repository\DashboardRepository;
use App\Service\ChromeDetector;
use App\Service\CurrentDashboard;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class FeaturesExtension extends AbstractExtension
{
    public function __construct(
        private readonly ChromeDetector $chrome,
        private readonly DashboardRepository $dashboards,
        private readonly CurrentDashboard $current,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('archive_features', fn (): array => $this->chrome->availableFeatures()),
            new TwigFunction('all_dashboards', fn (): array => $this->dashboards->findAllOrdered()),
            new TwigFunction('current_dashboard', fn () => $this->current->tryGet()),
        ];
    }
}

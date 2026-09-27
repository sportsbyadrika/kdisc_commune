<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Space\CatalogService;
use App\Services\Space\ExplorerPresenter;
use App\Services\Space\FloorMapService;
use App\Services\Space\SeatHolder;

/**
 * Space Explorer (spec 5.1): Level 1 building (/spaces/explore) → Level 2 floor (/spaces/explore/{floor}).
 * Guests browse read-only; picking a seat asks them to sign in. Seat data comes from /api/space/*.
 */
final class ExplorerController extends Controller
{
    public function __construct(
        private readonly ExplorerPresenter $presenter,
        private readonly FloorMapService $maps,
        private readonly CatalogService $catalog,
        private readonly Session $session,
    ) {
    }

    public function building(Request $request): Response
    {
        $filters = $this->presenter->filters($request->all());
        return $this->view('site/explore/building', [
            'title' => 'Space Explorer',
            'filters' => $filters,
            'building' => $this->catalog->building(),
            'floors' => $this->presenter->buildingFloors($filters, $this->holder()),
            'categories' => $this->presenter->categories($filters['from']),
        ]);
    }

    public function floor(Request $request, string $floor): Response
    {
        $row = $this->maps->findFloor($floor) ?? throw new NotFoundException('That floor does not exist.');
        $filters = $this->presenter->filters($request->all());
        $holder = $this->holder();
        return $this->view('site/explore/floor', [
            'title' => $row['name'] . ' · Space Explorer',
            'floor' => $row,
            'filters' => $filters,
            'config' => $this->presenter->floorConfig($row, $filters, false, $holder),
        ]);
    }

    private function holder(): ?SeatHolder
    {
        $guard = App::guard('visitor');
        return $guard->check() ? SeatHolder::account((int) $guard->id(), $this->session->id()) : null;
    }
}

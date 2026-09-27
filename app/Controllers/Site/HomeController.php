<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\Response;
use App\Services\Space\CatalogService;

final class HomeController extends Controller
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    public function index(): Response
    {
        $facilities = $this->catalog->facilitiesByKind();
        return $this->view('site/home', [
            'title' => 'Work near home, Kottarakara',
            'spaceTypes' => $this->catalog->spaceTypes(),
            'included' => $facilities['included'],
            'addons' => $facilities['addon'],
            'floors' => $this->catalog->floors(),
            'building' => $this->catalog->building(),
            'totalSeats' => $this->catalog->totalSeats(),
        ]);
    }
}

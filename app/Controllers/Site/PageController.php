<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\Response;
use App\Services\Space\CatalogService;

/** Mostly-static marketing pages. */
final class PageController extends Controller
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    public function pricing(): Response
    {
        return $this->view('site/pricing', [
            'title' => 'Pricing',
            'spaceTypes' => $this->catalog->spaceTypes(),
            'addons' => $this->catalog->facilitiesByKind()['addon'],
        ]);
    }

    public function facilities(): Response
    {
        return $this->view('site/facilities', [
            'title' => 'Facilities',
            'facilities' => $this->catalog->facilitiesByKind(),
        ]);
    }

    public function about(): Response
    {
        return $this->view('site/about', [
            'title' => 'About Commune',
            'totalSeats' => $this->catalog->totalSeats(),
            'building' => $this->catalog->building(),
        ]);
    }

    public function privacy(): Response
    {
        return $this->view('site/privacy', ['title' => 'Privacy policy']);
    }

    public function terms(): Response
    {
        return $this->view('site/terms', ['title' => 'Terms of use']);
    }
}

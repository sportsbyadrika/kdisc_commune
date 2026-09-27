<?php

declare(strict_types=1);

namespace App\Controllers\Site;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Space\CatalogService;

/** /spaces — building overview (entry point to the Space Explorer built in batch 3). */
final class SpaceController extends Controller
{
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    public function index(Request $request): Response
    {
        $from = $request->string('from');
        $to = $request->string('to');
        $valid = static fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);

        return $this->view('site/spaces', [
            'title' => 'Spaces',
            'spaceTypes' => $this->catalog->spaceTypes(),
            'included' => $this->catalog->facilitiesByKind()['included'],
            'floors' => $this->catalog->floors(),
            'building' => $this->catalog->building(),
            'from' => $valid($from) ? $from : '',
            'to' => $valid($to) ? $to : '',
            'type' => $request->string('type'),
        ]);
    }
}

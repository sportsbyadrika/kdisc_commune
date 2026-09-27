<?php
/**
 * Hidden SVG sprite with the Lucide icons the seat maps draw (<use href="#i-armchair">) — Space Explorer and
 * Layout Designer. Facility icons are chosen in the facility master, so callers pass them as $extra.
 *
 * @var list<string|null>|null $extra
 */
$icons = [
    'armchair', 'check', 'hourglass', 'user', 'lock', 'clock', 'door-open', 'presentation', 'info',
    'wifi', 'snowflake', 'plug', 'coffee', 'toilet', 'circle-parking', 'printer', 'projector', 'package',
    'arrow-up-down', 'fire-extinguisher', 'accessibility',
];
$icons = array_values(array_unique(array_merge($icons, array_filter($extra ?? [], static fn ($i) => is_string($i) && $i !== ''))));
?>
<svg width="0" height="0" class="absolute" aria-hidden="true" focusable="false"><defs><?php foreach ($icons as $name): ?><?= icon_symbol($name) ?><?php endforeach ?></defs></svg>

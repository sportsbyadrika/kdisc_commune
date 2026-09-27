<?php
/**
 * Hidden SVG sprite with the Lucide icons the Space Explorer map draws (<use href="#i-armchair">).
 * Add a name here when explorer.js / FacilitySeeder starts using a new icon.
 */
$icons = [
    'armchair', 'check', 'hourglass', 'user', 'lock', 'clock', 'door-open', 'presentation', 'info',
    'wifi', 'snowflake', 'plug', 'coffee', 'toilet', 'circle-parking', 'printer', 'projector', 'package',
    'arrow-up-down', 'fire-extinguisher', 'accessibility',
];
?>
<svg width="0" height="0" class="absolute" aria-hidden="true" focusable="false"><defs><?php foreach ($icons as $name): ?><?= icon_symbol($name) ?><?php endforeach ?></defs></svg>

<?php

/**
 * Baut die Orte wie im Live-System um: wenige Orte mit mehreren Helfertypen.
 *
 * Bisher gab es pro Helfertyp einen gleichnamigen Ort. Die Schichten dieser Orte werden
 * auf den neuen Ort verschoben, Schichten mit gleicher Zeit, gleichem Schichttyp und Titel
 * zu einer Schicht mit mehreren Helfertypen zusammengelegt und die leeren Orte gelöscht.
 * Kann mehrfach ausgeführt werden.
 *
 * Aufruf: docker compose cp helfer/setup/orte-mit-helfertypen.php es_server:/tmp/orte.php
 *         docker compose exec es_server php /tmp/orte.php [--dry-run]
 */

use Engelsystem\Models\AngelType;
use Engelsystem\Models\EventConfig;
use Engelsystem\Models\Location;
use Engelsystem\Models\Shifts\Shift;
use Illuminate\Database\Capsule\Manager as Capsule;

require_once '/var/www/includes/application.php';
app();

// Ort => Helfertypen, Reihenfolge wie im Tagesplan
$structure = [
    'Atelier'       => ['Kerzenatelier', 'Atelier Leitung'],
    'Aussenbereich' => ['Aussenbereich Fackeln', 'Aussenbereich Bienenwachs'],
    'Bistro'        => ['Bistro', 'Bistro Leitung'],
    'Kasse'         => ['Kasse'],
    'Kerzenziehen'  => [
        'Kerzenziehen Helfer',
        'Kerzenverzieren',
        'Springer',
        'Kerzenziehen / Res Kasse',
        'Dochtstation',
        'Selbstverziertisch',
    ],
    'Parkplatz'     => ['Parkplatz'],
    'Wachsküche'    => ['Wachsküche'],
    'Wachsschalen'  => ['Wachsschalen'],
];
$dryRun = in_array('--dry-run', $argv, true);

$db = Capsule::connection();
$db->beginTransaction();

foreach ($structure as $locationName => $typeNames) {
    $location = Location::firstOrCreate(['name' => $locationName]);
    echo "Ort {$location->name} (#{$location->id})\n";

    foreach ($typeNames as $typeName) {
        AngelType::where('name', $typeName)->firstOrFail();

        // The old location named like the angel type
        $old = Location::where('name', $typeName)->where('id', '!=', $location->id)->first();
        if ($old) {
            $moved = Shift::where('location_id', $old->id)->update(['location_id' => $location->id]);
            echo "  {$typeName}: {$moved} Schichten von Ort #{$old->id} verschoben\n";
        }
    }

    // Merge shifts with the same time, shift type and title into one shift with several angel types
    $shifts = Shift::where('location_id', $location->id)->orderBy('id')->get()
        ->groupBy(fn(Shift $shift) => implode('|', [
            $shift->start->timestamp,
            $shift->end->timestamp,
            $shift->shift_type_id,
            $shift->title,
        ]));
    $merged = 0;
    foreach ($shifts as $same) {
        $keep = $same->shift();
        foreach ($same as $shift) {
            foreach (['needed_angel_types', 'shift_entries', 'shift_tags', 'schedule_shift'] as $table) {
                $db->table($table)->where('shift_id', $shift->id)->update(['shift_id' => $keep->id]);
            }
            $shift->delete();
            $merged++;
        }
    }
    echo "  {$merged} Schichten zusammengelegt, jetzt "
        . Shift::where('location_id', $location->id)->count() . " Schichten\n";
}

// Remove the old locations that are empty now
$targets = array_keys($structure);
foreach (Location::whereNotIn('name', $targets)->get() as $old) {
    if (Shift::where('location_id', $old->id)->exists()) {
        echo "Ort {$old->name} hat noch Schichten, bleibt bestehen\n";
        continue;
    }
    echo "Ort {$old->name} (#{$old->id}) gelöscht\n";
    $old->delete();
}

// Order in the day plan: locations with their angel types (indented)
$order = '';
foreach ($structure as $locationName => $typeNames) {
    $order .= $locationName . "\n" . implode('', array_map(fn($name) => "    {$name}\n", $typeNames));
}
EventConfig::updateOrCreate(['name' => 'tagesplan_order'], ['value' => rtrim($order)]);
echo "Reihenfolge im Tagesplan gesetzt\n";

if ($dryRun) {
    $db->rollBack();
    echo "Testlauf, nichts gespeichert\n";
} else {
    $db->commit();
}

<?php

declare(strict_types=1);

namespace Helfersystem\Schichtimport;

use Engelsystem\Controllers\BaseController;
use Engelsystem\Controllers\HasUserNotifications;
use Engelsystem\Controllers\NotificationType;
use Engelsystem\Helpers\Authenticator;
use Engelsystem\Helpers\Carbon;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;
use Engelsystem\Models\AngelType;
use Engelsystem\Models\Location;
use Engelsystem\Models\Shifts\Shift;
use Engelsystem\Models\Shifts\ShiftEntry;
use Engelsystem\Models\Shifts\ShiftType;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Imports shifts with their needed angel types from the Excel template, with a check before the import
 */
class Controller extends BaseController
{
    use HasUserNotifications;

    protected array $permissions = [
        'admin_shifts',
    ];

    public function __construct(
        protected Authenticator $auth,
        protected Response $response
    ) {
    }

    public function index(Request $request): Response
    {
        $imports = Capsule::table('schichtimport_imports')->orderByDesc('id')->get()
            ->map(function ($import) {
                $shifts = Shift::where('transaction_id', $import->transaction_id);
                $import->remaining = (clone $shifts)->count();
                $import->entries = ShiftEntry::whereIn('shift_id', (clone $shifts)->select('id'))->count();
                $import->first = (clone $shifts)->min('start');
                $import->last = (clone $shifts)->max('start');
                return $import;
            });

        $range = Shift::query()->toBase()->selectRaw('MIN(`start`) AS `first`, MAX(`start`) AS `last`')->first();

        return $this->response->withView('schichtimport/index', [
            'imports' => $imports,
            'shift_types' => ShiftType::orderBy('name')->pluck('name', 'id'),
            'default_shift_type' => $this->mainShiftType(),
            'delete' => $this->deletePreview($request),
            'first' => $range?->first ? Carbon::make($range->first) : null,
            'last' => $range?->last ? Carbon::make($range->last) : null,
        ]);
    }

    /**
     * The empty template or the existing shifts as template, moved by some days (364 days = same weekday next year)
     */
    public function template(Request $request): Response
    {
        $withShifts = $request->input('inhalt') == 'schichten';
        $days = (int) $request->input('tage', 0);

        $xlsx = new TemplateWriter();
        $sheet = $xlsx->addSheet(Import::SHEET);
        $widths = [12, 8, 8, 18, 26, 9, 18, 22, 40];
        $column = 1;
        foreach (Import::COLUMNS as $header) {
            $xlsx->cell($sheet, 1, $column, $header, TemplateWriter::STYLE_HEADER);
            // Date and times as text, so Excel does not change their format
            $xlsx->column($sheet, $column, $widths[$column - 1], $column <= 3 ? TemplateWriter::STYLE_TEXT : 0);
            $column++;
        }
        $xlsx->freezeHeader($sheet);

        $row = 2;
        if ($withShifts) {
            $shifts = Shift::with(['location', 'shiftType', 'neededAngelTypes.angelType'])->orderBy('start')->get();
            foreach ($shifts as $shift) {
                $start = $shift->start->copy()->addDays($days);
                $end = $shift->end->copy()->addDays($days);
                foreach ($shift->neededAngelTypes as $need) {
                    if (!$need->angelType || $need->count < 1) {
                        continue;
                    }
                    $values = [
                        $start->format('d.m.Y'),
                        $start->format('H:i'),
                        $end->format('H:i'),
                        $shift->location?->name,
                        $need->angelType->name,
                        (int) $need->count,
                        $shift->shiftType?->name,
                        $shift->title,
                        $shift->description,
                    ];
                    foreach ($values as $index => $value) {
                        $xlsx->cell($sheet, $row, $index + 1, $value, $index <= 2 ? TemplateWriter::STYLE_TEXT : 0);
                    }
                    $row++;
                }
            }
        }

        // Lists for the drop-downs, other names are possible (they are created during the import if wanted)
        $lists = $xlsx->addSheet('Listen');
        $values = [
            'Orte' => Location::orderBy('name')->pluck('name')->all(),
            'Helfertypen' => AngelType::orderBy('name')->pluck('name')->all(),
            'Schichttypen' => ShiftType::orderBy('name')->pluck('name')->all(),
        ];
        $column = 1;
        foreach ($values as $header => $names) {
            $xlsx->cell($lists, 1, $column, $header, TemplateWriter::STYLE_HEADER);
            $xlsx->column($lists, $column, 28);
            foreach ($names as $index => $name) {
                $xlsx->cell($lists, $index + 2, $column, $name);
            }
            $column++;
        }
        $last = max(2000, $row + 500);
        $xlsx->listValidation($sheet, 'D2:D' . $last, 'Listen!$A$2:$A$' . (count($values['Orte']) + 50));
        $xlsx->listValidation($sheet, 'E2:E' . $last, 'Listen!$B$2:$B$' . (count($values['Helfertypen']) + 50));
        $xlsx->listValidation($sheet, 'G2:G' . $last, 'Listen!$C$2:$C$' . (count($values['Schichttypen']) + 50));
        $xlsx->wholeNumberValidation($sheet, 'F2:F' . $last);

        $this->instructions($xlsx);

        $filename = $withShifts ? 'Schichtimport-Vorlage-mit-Schichten.xlsx' : 'Schichtimport-Vorlage.xlsx';

        return $this->response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withContent($xlsx->output());
    }

    /**
     * Reads the uploaded file and shows what would be imported
     */
    public function check(Request $request): Response
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('datei');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->back(__('schichtimport.error.upload'), NotificationType::ERROR);
        }

        $create = (bool) $request->postData('anlegen');
        $defaultShiftType = (string) ShiftType::find((int) $request->postData('schichttyp'))?->name
            ?: (string) ShiftType::find($this->mainShiftType())?->name;
        $filename = $file->getClientOriginalName();

        $import = new Import();
        try {
            $rows = Import::rowsFromFile((string) file_get_contents($file->getPathname()), $filename);
        } catch (Throwable) {
            return $this->back(__('schichtimport.error.file'), NotificationType::ERROR);
        }
        $shifts = $import->parse($rows, $defaultShiftType);
        $resolved = $import->resolve($shifts, $create);
        $shifts = $resolved['shifts'];

        if (!$shifts && !$import->errors) {
            $import->errors[] = __('schichtimport.error.empty');
        }

        $token = null;
        if (!$import->errors) {
            $token = (string) Str::uuid();
            $this->cleanup();
            file_put_contents($this->path($token), json_encode([
                'user_id' => $this->auth->user()->id,
                'filename' => $filename,
                'create' => $create,
                'shifts' => $shifts,
            ]));
        }

        $days = [];
        foreach ($shifts as $shift) {
            $day = substr($shift['start'], 0, 10);
            $days[$day] ??= ['shifts' => 0, 'positions' => 0, 'existing' => 0, 'locations' => []];
            $days[$day][$shift['exists'] ? 'existing' : 'shifts']++;
            if (!$shift['exists']) {
                $days[$day]['positions'] += array_sum($shift['needs']);
                $days[$day]['locations'][$shift['location']] = true;
            }
        }
        ksort($days);

        $new = array_filter($shifts, fn($shift) => !$shift['exists']);

        return $this->response->withView('schichtimport/check', [
            'filename' => $filename,
            'token' => $token,
            'create' => $create,
            'errors' => $import->errors,
            'warnings' => $import->warnings,
            'new_names' => $resolved['new'],
            'shifts' => $shifts,
            'days' => $days,
            'total_shifts' => count($new),
            'total_positions' => array_sum(array_map(fn($shift) => array_sum($shift['needs']), $new)),
            'default_shift_type' => $defaultShiftType,
        ]);
    }

    public function import(Request $request): Response
    {
        $token = (string) $request->postData('token');
        $path = Str::isUuid($token) ? $this->path($token) : null;
        $data = $path && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!$data || $data['user_id'] != $this->auth->user()->id) {
            return $this->back(__('schichtimport.error.expired'), NotificationType::ERROR);
        }

        $transactionId = (string) Str::uuid();
        $import = new Import();
        try {
            $created = $import->execute($data['shifts'], (bool) $data['create'], $transactionId, $this->auth->user()->id);
        } catch (Throwable $e) {
            return $this->back(__('schichtimport.error.import', [$e->getMessage()]), NotificationType::ERROR);
        }
        unlink($path);

        Capsule::table('schichtimport_imports')->insert([
            'transaction_id' => $transactionId,
            'filename' => mb_substr($data['filename'], 0, 255),
            'shifts' => $created,
            'user_id' => $this->auth->user()->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        engelsystem_log(sprintf('Schichtimport: %d Schichten aus %s importiert (%s)', $created, $data['filename'], $transactionId));

        return $this->back(__('schichtimport.imported', [$created]));
    }

    /**
     * Removes the shifts of an import, as long as nobody signed up for them
     */
    public function undo(Request $request): Response
    {
        $transactionId = (string) $request->postData('transaction_id');
        $import = Capsule::table('schichtimport_imports')->where('transaction_id', $transactionId)->first();
        if (!$import) {
            return $this->back(__('schichtimport.error.not_found'), NotificationType::ERROR);
        }

        $shifts = Shift::where('transaction_id', $transactionId)->get();
        if (ShiftEntry::whereIn('shift_id', $shifts->pluck('id'))->exists()) {
            return $this->back(__('schichtimport.error.has_entries'), NotificationType::ERROR);
        }

        Capsule::connection()->transaction(function () use ($shifts, $transactionId): void {
            $shifts->each(fn(Shift $shift) => $shift->delete());
            Capsule::table('schichtimport_imports')->where('transaction_id', $transactionId)->delete();
        });
        engelsystem_log(sprintf('Schichtimport: %d Schichten des Imports %s gelöscht', $shifts->count(), $transactionId));

        return $this->back(__('schichtimport.undone', [$shifts->count()]));
    }

    /**
     * Deletes the shifts of a day or a year at a location (or at all locations),
     * signed up people are informed like when deleting shifts in the core
     */
    public function delete(Request $request): Response
    {
        $day = (string) $request->postData('tag');
        $locationId = (int) $request->postData('ort');
        $shifts = $this->shiftsOfDay($day, $locationId);
        if ($shifts === null || $shifts->isEmpty()) {
            return $this->back(__('schichtimport.delete.none'), NotificationType::ERROR);
        }

        $entries = $shifts->sum(fn(Shift $shift) => $shift->shiftEntries->count());
        if ($entries > 0 && !$request->postData('mit_eintragungen')) {
            return $this->response->redirectTo(url('/schichtimport', ['tag' => $day, 'ort' => $locationId]) . '#loeschen');
        }

        Capsule::connection()->transaction(function () use ($shifts): void {
            foreach ($shifts as $shift) {
                event('shift.deleting', ['shift' => $shift]);
                $shift->delete();
            }
        });
        engelsystem_log(sprintf(
            'Schichtimport: %d Schichten (%s) gelöscht (%s, %d Eintragungen)',
            $shifts->count(),
            $day,
            $locationId ? Location::find($locationId)?->name : 'alle Orte',
            $entries
        ));

        return $this->back(__('schichtimport.delete.done', [$shifts->count()]));
    }

    /**
     * Days and locations to choose and the shifts that would be deleted
     */
    protected function deletePreview(Request $request): array
    {
        $day = (string) $request->input('tag');
        $locationId = (int) $request->input('ort');
        $shifts = $day ? $this->shiftsOfDay($day, $locationId) : null;

        return [
            'years' => Shift::query()->toBase()
                ->selectRaw('DISTINCT YEAR(`start`) AS `year`')
                ->orderBy('year')
                ->pluck('year')
                ->map(fn($year) => (string) $year)
                ->all(),
            'days' => Shift::query()->toBase()
                ->selectRaw('DISTINCT DATE(`start`) AS `day`')
                ->orderBy('day')
                ->pluck('day')
                ->mapWithKeys(fn($value) => [(string) $value => Carbon::make($value)->format('d.m.Y')])
                ->all(),
            'locations' => Location::orderBy('name')->pluck('name', 'id')->all(),
            'day' => $day,
            'location' => $locationId,
            'shifts' => $shifts,
            'entries' => $shifts?->sum(fn(Shift $shift) => $shift->shiftEntries->count()) ?? 0,
        ];
    }

    /**
     * Shifts starting on the day ("2027-11-24") or in the year ("2027"), at one location or at all locations (0)
     *
     * @return Collection|Shift[]|null
     */
    protected function shiftsOfDay(string $day, int $locationId): ?Collection
    {
        if (preg_match('/^\d{4}$/', $day)) {
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $day . '-01-01 00:00:00');
            $end = $start->copy()->addYear();
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $day . ' 00:00:00');
            $end = $start->copy()->addDay();
        } else {
            return null;
        }

        return Shift::with(['location', 'shiftType', 'neededAngelTypes.angelType', 'shiftEntries.user', 'shiftEntries.angelType'])
            ->where('start', '>=', $start)
            ->where('start', '<', $end)
            ->when($locationId, fn($query) => $query->where('location_id', $locationId))
            ->orderBy('start')
            ->get();
    }

    protected function instructions(TemplateWriter $xlsx): void
    {
        $sheet = $xlsx->addSheet('Anleitung');
        $xlsx->column($sheet, 1, 110, TemplateWriter::STYLE_WRAP);
        $lines = [
            ['Schichtimport – Anleitung', TemplateWriter::STYLE_TITLE],
            ['Im Blatt „Schichten“ steht pro Zeile ein benötigter Helfertyp einer Schicht. Zeilen mit gleichem Datum, '
                . 'gleicher Zeit, gleichem Ort, Schichttyp und Titel werden zu einer Schicht mit mehreren Helfertypen zusammengefasst.'],
            [''],
            ['Spalten', TemplateWriter::STYLE_TITLE],
            ['Datum: z. B. 24.11.2027 (Pflicht)'],
            ['Von / Bis: z. B. 08:30 und 12:00 (Pflicht). Endet eine Schicht um Mitternacht oder später, gilt der nächste Tag (z. B. 18:00 – 00:30).'],
            ['Ort: Name des Ortes, z. B. Kerzenziehen (Pflicht, Auswahlliste)'],
            ['Helfertyp: z. B. Springer (Pflicht, Auswahlliste)'],
            ['Anzahl: benötigte Personen dieses Helfertyps (Pflicht, ganze Zahl; 0 = Zeile wird ignoriert)'],
            ['Schichttyp: Kategorie, z. B. Kerzenziehen, Raclette-Plausch, Private Events (optional; leer = Standard aus dem Import-Formular)'],
            ['Titel / Beschreibung: optional, erscheinen bei der Schicht'],
            [''],
            ['Beispiel', TemplateWriter::STYLE_TITLE],
            ['24.11.2027 | 13:00 | 14:00 | Kerzenziehen | Springer | 4 | Kerzenziehen'],
            ['24.11.2027 | 13:00 | 14:00 | Kerzenziehen | Dochtstation | 2 | Kerzenziehen'],
            ['→ eine Schicht am Ort Kerzenziehen von 13:00 bis 14:00 mit 4 Springern und 2 Personen an der Dochtstation'],
            [''],
            ['Hinweise', TemplateWriter::STYLE_TITLE],
            ['Die Auswahllisten zeigen die Orte, Helfertypen und Schichttypen im System (Blatt „Listen“). '
                . 'Neue Namen sind möglich, wenn beim Import „Fehlende Orte, Helfertypen und Schichttypen anlegen“ angekreuzt ist.'],
            ['Vor dem Import wird die Datei geprüft und eine Übersicht angezeigt. Erst danach werden die Schichten angelegt.'],
            ['Bereits vorhandene gleiche Schichten (gleiche Zeit, Ort, Schichttyp und Titel) werden übersprungen.'],
            ['Ein Import kann rückgängig gemacht werden, solange sich noch niemand für seine Schichten eingetragen hat.'],
            ['Die Datei kann auch als CSV (Trennzeichen Semikolon) gespeichert und importiert werden.'],
        ];
        foreach ($lines as $index => $line) {
            $xlsx->cell($sheet, $index + 1, 1, $line[0], $line[1] ?? TemplateWriter::STYLE_WRAP);
        }
    }

    /**
     * Shift type of most shifts
     */
    protected function mainShiftType(): ?int
    {
        return Shift::query()->toBase()
            ->select('shift_type_id')
            ->groupBy('shift_type_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('shift_type_id')
            ?? ShiftType::orderBy('id')->value('id');
    }

    protected function path(string $token): string
    {
        $directory = app('path.storage.app') . DIRECTORY_SEPARATOR . 'schichtimport';
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        return $directory . DIRECTORY_SEPARATOR . $token . '.json';
    }

    /**
     * Removes checked files that were not imported within a day
     */
    protected function cleanup(): void
    {
        foreach (glob(dirname($this->path('x')) . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (filemtime($file) < time() - 86400) {
                unlink($file);
            }
        }
    }

    protected function back(string $message, NotificationType $type = NotificationType::MESSAGE): Response
    {
        $this->addNotification($message, $type);

        return $this->response->redirectTo(url('/schichtimport'));
    }
}

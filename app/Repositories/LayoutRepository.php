<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\EventHasLayout;
use App\Models\EventSeatStatus;
use App\Models\Layout;
use App\Models\LRow;
use App\Models\LSeat;
use App\Models\LSection;
use App\Models\LStage;

class LayoutRepository
{
    /**
     * Get all layouts with venue information
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllLayouts()
    {
        return Layout::with('venue:id,name')
            ->select('id', 'name', 'stage_config', 'event_id', 'venue_id', 'total_section', 'total_row', 'total_seat', 'created_at')
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Create a new layout with stage and sections
     * @param array $data
     * @return Layout
     */
    public function createLayout(array $data)
    {
        $meta = $data['metadata'] ?? [];

        $totalSections = $meta['totalSections'] ?? 0;
        $totalSeats    = $meta['totalSeats'] ?? 0;
        $totalRows     = $meta['totalRows'] ?? 0;

        // 1️⃣ Create Layout entry
        $layout = Layout::create([
            'event_id' => $data['event_id'] ?? null,
            'event_key' => $data['event_key'] ?? null,
            'venue_id' => $data['venue_id'] ?? null,
            'name' => $data['name'] ?? 'Custom Layout',
            'total_section' => $totalSections,
            'total_seat'     => $totalSeats,
            'total_row'     => $totalRows,
            'stage_config' => json_encode($data['stage'] ?? []),
        ]);

        /* -----------------------------------------------------------
            2️⃣ SAVE STAGE
        ------------------------------------------------------------ */
        if (!empty($data['stage'])) {
            LStage::create([
                'layout_id' => $layout->id,
                'name' => $data['stage']['name'] ?? null,
                'position' => $data['stage']['position'] ?? null,
                'shape' => $data['stage']['shape'] ?? null,
                'height' => $data['stage']['height'] ?? null,
                'width' => $data['stage']['width'] ?? null,
                'x' => $data['stage']['x'] ?? null,
                'y' => $data['stage']['y'] ?? null,
                'status' => 'active',
            ]);
        }

        /* -----------------------------------------------------------
            3️⃣ SAVE SECTIONS + ROWS + SEATS
        ------------------------------------------------------------ */
        foreach ($data['sections'] as $section) {

            // Save Section
            $sectionDB = LSection::create([
                'tier_id' => null,
                'name' => $section['name'],
                'layout_id' => $layout->id,
                'type' => $section['type'] ?? null,
                'position' => json_encode(['x' => $section['x'], 'y' => $section['y']]),
                'width' => $section['width'],
                'height' => $section['height'],
            ]);

            // Loop Rows
            foreach ($section['rows'] as $row) {

                $rowDB = LRow::create([
                    'section_id' => $sectionDB->id,
                    'label' => $row['title'],
                    'seats' => $row['numberOfSeats'],
                    'row_shape' => $row['shape'],
                    'curve_amount' => $row['curve'],
                    'spacing' => $row['spacing'],
                    'ticket_id' => $row['ticketCategory'] ?? null,
                ]);

                // Loop Seats
                foreach ($row['seats'] as $seat) {

                    LSeat::create([
                        'row_id' => $rowDB->id,
                        'section_id' => $sectionDB->id,
                        'seat_no' => $seat['number'],
                        'label' => $seat['label'],
                        'status' => $seat['status'],
                        'price' => null,
                        'ticket_id' => $seat['ticketCategory'],
                        'position' => json_encode(['x' => $seat['x'], 'y' => $seat['y']]),
                        'seat_icon' => $seat['icon'] ?? null,
                        'seat_reading' => $seat['radius'] ?? null,
                        'type' => $seat['type'] ?? null,
                    ]);
                }
            }
        }

        return $layout;
    }

    /**
     * Duplicate an existing layout
     * @param int $oldLayoutId
     * @param string $newLayoutName
     * @return Layout|null
     */
    public function duplicateLayout($oldLayoutId, $newLayoutName = 'Cloned Layout')
    {
        // 1️⃣ Get Main Layout
        $layout = Layout::find($oldLayoutId);
        if (!$layout) {
            return null;
        }

        // 2️⃣ Duplicate Layout
        $newLayout = Layout::create([
            'event_id' => $layout->event_id,
            'event_key' => $layout->event_key,
            'venue_id' => $layout->venue_id,
            'name' => $newLayoutName,
            'total_section' => $layout->total_section,
            'total_seat' => $layout->total_seat,
            'total_row' => $layout->total_row,
            'stage_config' => $layout->stage_config,
        ]);

        /* ----------------------------------------------------
           3️⃣ Copy Stage
        ----------------------------------------------------*/
        $stage = LStage::where('layout_id', $oldLayoutId)->first();
        if ($stage) {
            LStage::create([
                'layout_id' => $newLayout->id,
                'name' => $stage->name,
                'position' => $stage->position,
                'shape' => $stage->shape,
                'height' => $stage->height,
                'width' => $stage->width,
                'x' => $stage->x,
                'y' => $stage->y,
                'status' => $stage->status,
            ]);
        }

        /* ----------------------------------------------------
           4️⃣ Copy Sections → Rows → Seats
        ----------------------------------------------------*/
        $sections = LSection::where('layout_id', $oldLayoutId)->get();
        foreach ($sections as $sec) {

            // Copy Section
            $newSection = LSection::create([
                'tier_id' => $sec->tier_id,
                'name' => $sec->name,
                'layout_id' => $newLayout->id,
                'type' => $sec->type,
                'position' => $sec->position,
                'width' => $sec->width,
                'height' => $sec->height,
            ]);

            $rows = LRow::where('section_id', $sec->id)->get();
            foreach ($rows as $row) {

                // Copy Row
                $newRow = LRow::create([
                    'section_id' => $newSection->id,
                    'label' => $row->label,
                    'seats' => $row->seats,
                    'row_shape' => $row->row_shape,
                    'curve_amount' => $row->curve_amount,
                    'spacing' => $row->spacing,
                    'ticket_id' => $row->ticket_id,
                ]);

                $seats = LSeat::where('row_id', $row->id)->get();
                foreach ($seats as $seat) {
                    // Copy Seats
                    LSeat::create([
                        'row_id' => $newRow->id,
                        'section_id' => $newSection->id,
                        'seat_no' => $seat->seat_no,
                        'label' => $seat->label,
                        'status' => $seat->status,
                        'price' => $seat->price,
                        'ticket_id' => $seat->ticket_id,
                        'position' => $seat->position,
                        'seat_icon' => $seat->seat_icon,
                        'seat_reading' => $seat->seat_reading,
                        'type' => $seat->type,
                    ]);
                }
            }
        }

        return $newLayout;
    }

    /**
     * Update an existing layout
     * @param int $layoutId
     * @param array $data
     * @return Layout
     */
    public function updateLayout($layoutId, array $data)
    {
        $meta = $data['metadata'] ?? [];

        $layout = Layout::findOrFail($layoutId);

        $layout->update([
            'event_id' => $data['event_id'] ?? $layout->event_id,
            'event_key' => $data['event_key'] ?? $layout->event_key,
            'venue_id' => $data['venue_id'] ?? $layout->venue_id,
            'name' => $data['name'] ?? $layout->name,
            'total_section' => $meta['totalSections'] ?? 0,
            'total_seat' => $meta['totalSeats'] ?? 0,
            'total_row' => $meta['totalRows'] ?? 0,
            'stage_config' => json_encode($data['stage'] ?? []),
        ]);

        /* INSERT STAGE */
        if (!empty($data['stage'])) {
            LStage::create([
                'layout_id' => $layout->id,
                'name' => $data['stage']['name'] ?? null,
                'position' => $data['stage']['position'] ?? null,
                'shape' => $data['stage']['shape'] ?? null,
                'height' => $data['stage']['height'] ?? null,
                'width' => $data['stage']['width'] ?? null,
                'x' => $data['stage']['x'] ?? null,
                'y' => $data['stage']['y'] ?? null,
                'status' => 'active',
            ]);
        }

        /* INSERT SECTIONS → ROWS → SEATS */
        $resolveTicketId = function ($value, $fallback = null) {
            if (is_array($value)) {
                return $value['id'] ?? $value['ticketId'] ?? $value['ticket_id'] ?? $fallback;
            }
            if (is_object($value)) {
                return $value->id ?? $value->ticketId ?? $value->ticket_id ?? $fallback;
            }
            return $value ?? $fallback;
        };

        foreach ($data['sections'] as $section) {

            $sectionDB = LSection::create([
                'tier_id' => null,
                'name' => $section['name'],
                'layout_id' => $layout->id,
                'type' => $section['type'] ?? null,
                'position' => json_encode(['x' => $section['x'], 'y' => $section['y']]),
                'width' => $section['width'],
                'height' => $section['height'],
            ]);

            foreach ($section['rows'] as $row) {
                $rowDB = LRow::create([
                    'section_id' => $sectionDB->id,
                    'label' => $row['title'],
                    'seats' => $row['numberOfSeats'],
                    'row_shape' => $row['shape'],
                    'curve_amount' => $row['curve'],
                    'spacing' => $row['spacing'],
                    'ticket_id' => $row['ticketCategory'] ?? null,
                ]);

                foreach ($row['seats'] as $seat) {
                    LSeat::create([
                        'row_id' => $rowDB->id,
                        'section_id' => $sectionDB->id,
                        'seat_no' => $seat['number'],
                        'label' => $seat['label'],
                        'status' => is_array($seat['status']) || is_object($seat['status']) ? ($seat['status']['value'] ?? $seat['status']->value ?? $row['status'] ?? 'available') : $seat['status'],
                        'price' => null,
                        'ticket_id' => $resolveTicketId($seat['ticket'] ?? ($row['ticketCategory'] ?? null)),
                        'position' => json_encode(['x' => $seat['x'], 'y' => $seat['y']]),
                        'seat_icon' => $seat['icon'] ?? null,
                        'seat_reading' => $seat['radius'] ?? null,
                        'type' => $seat['type'] ?? null,
                    ]);
                }
            }
        }

        return $layout;
    }

    /**
     * Get layout with all relationships for viewing
     * @param int $layoutId
     * @return Layout
     */
    public function getLayoutWithDetails($layoutId)
    {
        return Layout::with([
            'stage',
            'sections.rows.seatList',
            'event.tickets'
        ])->find($layoutId);
    }

    /**
     * Build full layout response data
     * @param Layout $layout
     * @param string|null $eventId
     * @param array $lockedSeats
     * @return array
     */
    public function buildLayoutResponse(Layout $layout, $eventId = null, array $lockedSeats = [])
    {
        // Build Response
        $response = [
            "id" => "layout_" . $layout->id,
            "name" => $layout->name,
            "createdAt" => $layout->created_at,
            "updatedAt" => $layout->updated_at,
            "venue_id" => $layout->venue_id,

            // Stage
            "stage" => [
                "position" => $layout->stage->position ?? null,
                "shape"    => $layout->stage->shape ?? null,
                "width"    => $layout->stage->width ?? null,
                "height"   => $layout->stage->height ?? null,
                "x"        => $layout->stage->x ?? null,
                "y"        => $layout->stage->y ?? null,
                "name"     => $layout->stage->name ?? null,
            ],

            "sections" => [],


            "ticketCategories" => $layout->event && $layout->event->tickets
                ? $layout->event->tickets->map(function ($t) {
                    return [
                        "id"   => $t->id,
                        "name" => $t->name,
                        "price" => $t->price,
                    ];
                })
                : [],


            "metadata" => [
                "createdAt"      => $layout->created_at,
                "updatedAt"      => $layout->updated_at,
                "totalSections"  => $layout->total_section,
                "totalSeats"     => $layout->total_seat,
                "totalRows"      => $layout->total_row,
            ]
        ];

        // Build maps once (avoid per-seat DB queries)
        $seatStatusMap = collect();
        $ticketMap = collect();

        // If eventId wasn't supplied as query param, try to resolve from EventHasLayout
        if (empty($eventId)) {
            $ehl = EventHasLayout::where('layout_id', $layout->id)->latest('id')->first();
            $eventId = $ehl->event_id ?? null;
        }

        if (!empty($eventId)) {
            // load all EventSeatStatus for this event once, eager-load ticket relation
            $seatStatusMap = EventSeatStatus::with('ticket')
                ->where('event_id', $eventId)
                ->select('seat_id', 'ticket_id', 'status', 'event_id')
                ->get()
                ->mapWithKeys(function ($row) {
                    $key = (string) $row->seat_id;
                    if (!str_starts_with($key, 'seat_')) {
                        $key = 'seat_' . $key;
                    }
                    return [$key => $row];
                });

            // build ticket map from event's tickets if event exists
            $eventModel = Event::find($eventId);
            if ($eventModel && $eventModel->tickets) {
                $ticketMap = $eventModel->tickets->keyBy('id');
            }
        }

        // Add Sections
        foreach ($layout->sections as $section) {

            $sectionArr = [
                "id"     => "section_" . $section->id,
                "name"   => $section->name,
                "type"   => $section->type,
                "x"      => json_decode($section->position)->x,
                "y"      => json_decode($section->position)->y,
                "width"  => $section->width,
                "height" => $section->height,
                'seatStatusMap' => $ticketMap,
                "rows"   => [],
                "subSections" => []
            ];

            // Rows
            foreach ($section->rows as $row) {

                $rowArr = [
                    "id"            => "row_" . $row->id,
                    "title"         => $row->label,
                    "numberOfSeats" => $row->seats, // INTEGER — OK
                    "ticketCategory" => $row->ticket_id,
                    "shape"         => $row->row_shape,
                    "curve"         => $row->curve_amount,
                    "spacing"       => $row->spacing,
                    "seats"         => []
                ];

                $statusLabel = [
                    0 => 'available',
                    1 => 'booked',
                    2 => 'disabled',
                ];

                // Seats (USE CORRECT RELATION)
                foreach ($row->seatList as $seat) {   // FIX APPLIED HERE
                    // lookup mapping from preloaded collection (no DB call per seat)
                    $ess = $seatStatusMap->get('seat_' . $seat->id);

                    // prefer associated ticket relation from EventSeatStatus, fallback to ticketMap
                    $ticketPayload = null;
                    if ($ess) {
                        $tModel = $ess->ticket ?? ($ticketMap->get($ess->ticket_id) ?? null);
                        if ($tModel) {
                            $ticketPayload = [
                                'id'    => $tModel->id,
                                'name'  => $tModel->name,
                                'price' => $tModel->price,
                            ];
                        }
                    }

                    // Determine status
                    $currentStatus = 'available';
                    if ($ess) {
                        $currentStatus = $statusLabel[$ess->status] ?? 'available';
                    }

                    // Check if seat is locked (hold) - only if currently available
                    // Note: lockedSeats keys are raw numeric IDs (e.g., 1468).
                    // We use array_key_exists because the value might be null, which isset() returns false for.
                    $holdSessionId = null;
                    if ($currentStatus === 'available') {
                        if (array_key_exists($seat->id, $lockedSeats)) {
                            $currentStatus = 'hold';
                            $holdSessionId = $lockedSeats[$seat->id];
                        } elseif (array_key_exists('seat_' . $seat->id, $lockedSeats)) {
                            $currentStatus = 'hold';
                            $holdSessionId = $lockedSeats['seat_' . $seat->id];
                        }

                        // Strip 'user_' prefix if present
                        if ($holdSessionId && str_starts_with($holdSessionId, 'user_')) {
                            $holdSessionId = substr($holdSessionId, 5);
                        }
                    }

                    $rowArr['seats'][] = [
                        'id'             => 'seat_' . $seat->id,
                        'number'         => $seat->seat_no,
                        'label'          => $seat->label,
                        'type'          => $seat->type,
                        'x'              => json_decode($seat->position)->x,
                        'y'              => json_decode($seat->position)->y,
                        // 'ticketCategory' => $seat->ticket_id,
                        // 'status'         => $seat->status,
                        'ticketCategory' => $ess->ticket_id ?? $seat->ticket_id,
                        'status'         => $currentStatus,
                        'hold_by'        => $holdSessionId, // Return session ID of the user holding the lock
                        'radius'         => $seat->seat_reading,
                        'icon'           => $seat->seat_icon,
                        'ticket'         => $ticketPayload,
                    ];
                }

                $sectionArr["rows"][] = $rowArr;
            }

            $response["sections"][] = $sectionArr;
        }

        return $response;
    }

    /**
     * Submit event layout with ticket assignments
     * @param int $eventId
     * @param int $layoutId
     * @param string $eventKey
     * @param array $ticketAssignments
     * @return void
     */
    public function submitEventLayout($eventId, $layoutId, $eventKey, array $ticketAssignments)
    {
        /*--------------------------------------------
          2️⃣ EventHasLayout ➜ updateOrCreate
        --------------------------------------------*/
        EventHasLayout::updateOrCreate(
            [
                'event_id'  => $eventId,
                'event_key' => $eventKey
            ],
            [
                'layout_id' => $layoutId
            ]
        );

        /*--------------------------------------------
          3️⃣ EventSeatStatus ➜ updateOrCreate every seat
        --------------------------------------------*/
        foreach ($ticketAssignments as $row) {

            $statusMap = [
                'available' => 0,
                'booked'    => 1,
                'disabled'   => 2,
            ];
            $status = $statusMap[strtolower($row['status'])] ?? 0;

            EventSeatStatus::updateOrCreate(
                [
                    'event_id'  => $eventId,
                    'seat_id'   => $row['seatId'],   // UNIQUE PER SEAT
                ],
                [
                    'event_key'  => $eventKey,
                    'ticket_id'  => $row['ticketId'],
                    'section_id' => $this->extractNumericId($row['sectionId'] ?? ($row['section_id'] ?? null)),
                    'status'     => $status
                ]
            );
        }
    }

    /**
     * Get event layout data
     * @param int $eventId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getEventLayout($eventId)
    {
        // 2️⃣ Status DB → String mapping
        $statusMap = [
            0 => 'available',
            1 => 'booked',
            2 => 'disable'
        ];

        // 3️⃣ Get seat mappings
        return EventSeatStatus::where('event_id', $eventId)
            ->select('seat_id', 'section_id', 'ticket_id', 'status')
            ->get()
            ->map(function ($row) use ($statusMap) {
                return [
                    'seatId'    => $row->seat_id,
                    'sectionId' => $row->section_id,
                    'ticketId'  => $row->ticket_id,
                    'status'    => $statusMap[$row->status] ?? 'available'
                ];
            });
    }

    /**
     * Delete layout and all related records
     * @param int $layoutId
     * @return bool
     * @throws \Exception
     */
    public function deleteLayout($layoutId)
    {
        // 1️⃣ Fetch all sections in layout
        $sections = LSection::where('layout_id', $layoutId)->pluck('id');

        // 2️⃣ Fetch all rows in sections
        $rows = LRow::whereIn('section_id', $sections)->pluck('id');

        // 3️⃣ Fetch all seats in rows
        $seats = LSeat::whereIn('row_id', $rows)->pluck('id');

        // 🔍 Check if any seat is already assigned in EventSeatStatus
        $assignedSeatExists = EventSeatStatus::whereIn('seat_id', $seats)->exists();

        if ($assignedSeatExists) {
            throw new \Exception('Layout cannot be deleted because seats are already assigned.');
        }

        // 🗑 Continue deletion only if no seat assigned
        LSeat::whereIn('id', $seats)->delete();
        LRow::whereIn('id', $rows)->delete();
        LSection::whereIn('id', $sections)->delete();
        EventHasLayout::where('layout_id', $layoutId)->delete();
        Layout::where('id', $layoutId)->delete();

        return true;
    }

    /**
     * Helper: Extract numeric ID from various formats
     * @param mixed $value
     * @return int|null
     */
    private function extractNumericId($value): ?int
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/(\d+)/', $value, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}

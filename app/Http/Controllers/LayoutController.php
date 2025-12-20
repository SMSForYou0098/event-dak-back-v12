<?php

namespace App\Http\Controllers;

use App\Http\Resources\EventLayoutResource;
use App\Http\Resources\LayoutResource;
use App\Models\Event;
use App\Repositories\LayoutRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LayoutController extends Controller
{
    protected $layoutRepository;

    public function __construct(LayoutRepository $layoutRepository)
    {
        $this->layoutRepository = $layoutRepository;
    }

    public function index()
    {
        $layouts = $this->layoutRepository->getAllLayouts();
        
        if ($layouts->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Layout not found'
            ], 200);
        }
        
        return response()->json([
            'status' => true,
            'data' => LayoutResource::collection($layouts),
        ], 200);
    }

    public function storeLayout(Request $request)
    {
        try {
            DB::beginTransaction();

            $data = $request->all();
            $layout = $this->layoutRepository->createLayout($data);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Layout saved successfully',
                'layout_id' => $layout->id
            ], 200);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function duplicateLayout(Request $request)
    {
        try {
            DB::beginTransaction();

            $oldLayoutId = $request->id;
            $newLayoutName = $request->name ?? 'Cloned Layout';

            $newLayout = $this->layoutRepository->duplicateLayout($oldLayoutId, $newLayoutName);

            if (!$newLayout) {
                return response()->json([
                    'status' => false,
                    'message' => 'Layout not found',
                ], 404);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Layout cloned successfully',
                'layout_id' => $newLayout->id,
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }


    public function updateLayout(Request $request, $layoutId)
    {
        try {
            DB::beginTransaction();

            $data = $request->all();
            $layout = $this->layoutRepository->updateLayout($layoutId, $data);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Layout updated successfully',
                'layout_id' => $layout->id
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }


    public function viewLayout($layoutId)
    {
        try {
            $eventId = request()->query('eventId');

            $layout = $this->layoutRepository->getLayoutWithDetails($layoutId);

            if (!$layout) {
                return response()->json([
                    'success' => false,
                    'message' => 'Layout not found'
                ], 404);
            }

            $response = $this->layoutRepository->buildLayoutResponse($layout, $eventId);

            return response()->json([
                "success" => true,
                "data" => $response
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    public function eventLayoutSubmit(Request $request, $event_key)
    {
        try {
            $layoutId = $request->layoutId;
            $eventKey = $request->eventId;
            $ticketAssignments = $request->ticketAssignments;
            
            // Find event by event_key or ID
            $event = Event::query();

            if (is_numeric($eventKey)) {
                $event->where('id', $eventKey);
            }

            if (!empty($eventKey)) {
                $event->orWhere('event_key', $eventKey);
            }

            $event = $event->first();

            if (!$event) {
                return response()->json([
                    'status' => false,
                    'message' => 'Event not found'
                ], 404);
            }

            $eventId = $event->id;

            $this->layoutRepository->submitEventLayout($eventId, $layoutId, $eventKey, $ticketAssignments);

            return response()->json([
                'status' => true,
                'message' => 'Layout & seat mapping updated successfully'
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function eventLayoutGet($event_key)
    {
        try {
            // 1️⃣ Find event using event_key
            $event = Event::where('event_key', $event_key)->first();

            if (!$event) {
                return response()->json([
                    'status' => false,
                    'message' => 'Event not found'
                ], 404);
            }

            $eventId = $event->id;

            // 3️⃣ Get seat mappings from repository
            $seatData = $this->layoutRepository->getEventLayout($eventId);

            return response()->json([
                'status' => true,
                'data'   => EventLayoutResource::collection($seatData)
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($layoutId)
    {
        DB::beginTransaction();

        try {
            $this->layoutRepository->deleteLayout($layoutId);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Layout & related records deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

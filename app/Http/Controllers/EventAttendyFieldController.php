<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventAttField;
use Illuminate\Http\Request;

class EventAttendyFieldController extends Controller
{
    public function eventFieldsList()
    {
        try {
            $records = EventAttField::with('event')->get();

            $records = $records->map(function ($record) {
                $record->custom_fields = $record->customFields();
                return $record;
            });

            return response()->json(['status' => true, 'data' => $records], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to retrieve records: ' . $e->getMessage()], 500);
        }
    }

    public function catrgotyFieldsListId($title)
    {
        try {
            $eventData = Event::where('name', $title)->first();

            if (!$eventData) {
                return response()->json(['status' => 'false', 'message' => 'event not found'], 404);
            }

            $records = EventAttField::with('event')->where('event_id', $eventData->id)->get();

            $records = $records->map(function ($record) {
                $record->custom_fields = $record->customFields();
                return $record;
            });

            return response()->json([
                'status' => true,
                'data' => $records
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to retrieve records: ' . $e->getMessage()
            ], 500);
        }
    }

    public function catrgotyFields(Request $request)
    {
        try {
            $customFieldsIds = $request->input('custom_fields_id');
            $customFieldsIdsString = implode(',', $customFieldsIds);

            $categoryHasField = EventAttField::updateOrCreate(
                ['event_id' => $request->input('event_id')],
                ['custom_fields_id' => $customFieldsIdsString]
            );

            return response()->json(['status' => true, 'message' => 'Record added or updated successfully', 'data' => $categoryHasField], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to add or update record: ' . $e->getMessage()], 500);
        }
    }


    //category-fields-update
    public function catrgotyFieldsUpdate(Request $request, $id)
    {
        try {

            $categoryHasField = EventAttField::findOrFail($id);

            $categoryHasField->event_id = $request->input('event_id');

            if ($request->has('custom_fields_id')) {
                $customFieldsIds = $request->input('custom_fields_id');
                $categoryHasField->custom_fields_id = implode(',', $customFieldsIds);
            }

            $categoryHasField->save();

            return response()->json([
                'status' => true,
                'message' => 'Record updated successfully',
                'data' => $categoryHasField
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update record: ' . $e->getMessage()
            ], 500);
        }
    }

    // Delete a record
    public function catrgotyFieldsdestroy($id)
    {
        $categoryHasField = EventAttField::findOrFail($id);
        $categoryHasField->delete();

        return response()->json(['status' => true, 'message' => 'Record deleted successfully'], 200);
    }
}

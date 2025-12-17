<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CatLayout;
use App\Models\Catrgoty_has_Field;
use App\Models\CustomField;
use App\Models\Event;
use App\Models\PaymentLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{

    public function index()
    {
        try {
            $categoryData = Category::with('CatrgotyhasField')->get();


            $categoryData->each(function ($category) {
                $fieldIds = $category->catrgotyhasField ? explode(',', $category->catrgotyhasField->custom_fields_id) : [];
                // Filter out empty strings and convert to integers, then filter out invalid values
                $fieldIds = array_filter(array_map('intval', array_filter($fieldIds, function ($id) {
                    return !empty(trim($id));
                })));

                if (!empty($fieldIds)) {
                    $category->fields = CustomField::whereIn('id', $fieldIds)
                        ->latest()
                        ->get();
                } else {
                    $category->fields = collect();
                }
            });

            if (!$categoryData) {
                return response()->json([
                    'status' => false,
                    'message' => 'categoryData not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'categoryData retrieved successfully',
                'categoryData' => $categoryData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'false',
                'message' => 'An error occurred while categoryData',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $categoryData = new Category();
            $categoryData->title = $request->title;
            $categoryData->url = $request->url;
            $categoryData->photo_required = filter_var($request->photo_required, FILTER_VALIDATE_BOOLEAN);
            $categoryData->attendy_required = filter_var($request->attendy_required, FILTER_VALIDATE_BOOLEAN);
            $categoryData->status = isset($request->status) ? filter_var($request->status, FILTER_VALIDATE_BOOLEAN) : true;

            if ($request->hasFile('image') && $request->file('image')->isValid()) {
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $request->file('image')->getClientOriginalName();
                $folder = 'categories';
                $imagePath = $this->storeFile($request->file('image'), $folder . '/' . $fileName);
                $categoryData->image = $imagePath;
            }
            if ($request->hasFile('card_url') && $request->file('card_url')->isValid()) {
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $request->file('card_url')->getClientOriginalName();
                $folder = 'categories/card_url';
                $imagePath = $this->storeFile($request->file('card_url'), $folder . '/' . $fileName);
                $categoryData->card_url = $imagePath;
            }

            $categoryData->save();


            return response()->json([
                'status' => true,
                'message' => 'Category Data created successfully',
                'categoryData' => $categoryData
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create category data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        $categoryData = Category::select('id', 'title')->findOrFail($id);
        if (!$categoryData) {
            return response()->json(['status' => false, 'message' => 'categoryData not found'], 404);
        }
        return response()->json([
            'status' => true,
            'message' => 'categoryData successfully',
            'categoryData' => $categoryData,
        ], 200);
    }

    public function update(Request $request, $id)
    {
        try {

            $categoryData = Category::findOrFail($id);

            $categoryData->title = $request->title;
            $categoryData->url = $request->url;
            $categoryData->photo_required = filter_var($request->photo_required, FILTER_VALIDATE_BOOLEAN);
            $categoryData->attendy_required = filter_var($request->attendy_required, FILTER_VALIDATE_BOOLEAN);
            $categoryData->status = isset($request->status) ? filter_var($request->status, FILTER_VALIDATE_BOOLEAN) : $categoryData->status;
            if ($request->hasFile('image') && $request->file('image')->isValid()) {
                if (!empty($categoryData->image)) {
                    $oldImagePath = str_replace(asset('storage/') . '/', '', $categoryData->image);
                    Storage::disk('public')->delete($oldImagePath);
                }
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $request->file('image')->getClientOriginalName();
                $folder = 'categories';
                $imagePath = $request->file('image')->storeAs($folder, $fileName, 'public');
                $categoryData->image = asset($imagePath);
            }
            if ($request->hasFile('card_url') && $request->file('card_url')->isValid()) {
                if (!empty($categoryData->card_url)) {
                    $oldImagePath = str_replace(asset('storage/') . '/', '', $categoryData->card_url);
                    Storage::disk('public')->delete($oldImagePath);
                }
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $request->file('card_url')->getClientOriginalName();
                $folder = 'categories/card_url';
                $imagePath = $request->file('card_url')->storeAs($folder, $fileName, 'public');
                $categoryData->card_url = asset($imagePath);
            }

            $categoryData->save();

            return response()->json(['status' => true, 'message' => 'Category Data updated successfully', 'categoryData' => $categoryData], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to update category data', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy(string $id)
    {
        $categoryData = Category::where('id', $id)->firstOrFail();
        if (!$categoryData) {
            return response()->json(['status' => false, 'message' => 'categoryData not found'], 404);
        }

        $categoryData->delete();
        return response()->json(['status' => true, 'message' => 'categoryData deleted successfully'], 200);
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }

    public function categoryTitle(Request $request, string $id)
    {

        $categoryData = Category::where('id', $id)->first();

        if (!$categoryData) {
            return response()->json([
                'status' => false,
                'message' => 'categoryData Title not found'
            ], 404);
        }

        $customFieldsData = null;
        if ($categoryData->attendy_required == 1) {
            $categoryHasField = $categoryData->CatrgotyhasField;

            if ($categoryHasField && !empty($categoryHasField->custom_fields_id)) {
                $fieldIds = explode(',', $categoryHasField->custom_fields_id);
                // Filter out empty strings and convert to integers
                $fieldIds = array_filter(array_map('intval', array_filter($fieldIds, function ($id) {
                    return !empty(trim($id));
                })));

                if (!empty($fieldIds)) {
                    $customFieldsData = CustomField::whereIn('id', $fieldIds)->orderBy('sr_no')->get();
                } else {
                    $customFieldsData = collect();
                }
            } else {
                $customFieldsData = collect();
            }
        }

        return response()->json([
            'status' => true,
            'categoryData' => $categoryData,
            'customFieldsData' => $customFieldsData,
            'message' => 'categoryData retrieved successfully'
        ], 200);
    }

    public function allCategoryTitle()
    {
        $categoryData = Category::where('status', 1)
            ->select('id', 'title', 'image')
            ->get();

        if (!$categoryData) {
            return response()->json([
                'status' => false,
                'message' => 'No active categories found.'
            ], 404);
        }
        return response()->json([
            'status' => true,
            'categoryData' => $categoryData,
            'message' => 'Active category titles retrieved successfully.'
        ], 200);
    }

    public function allData(Request $request)
    {
        Log::info('Payment Log:', $request->all());
        $logData = PaymentLog::create($request->all());
        return response()->json([
            'message' => 'Log data processed successfully!',
            'data' => $logData
        ], 200);
    }

    public function allCategoryImages(Request $request)
    {
        $categoryData = Category::select('id', 'title', 'image')->get();

        if (!$categoryData) {
            return response()->json([
                'status' => false,
                'message' => 'categoryData Title not found'
            ], 404);
        }
        return response()->json([
            'status' => true,
            'categoryData' => $categoryData,
            'message' => 'categoryData  successfully'
        ], 200);
    }

    public function layoutList($user_id)
    {

        $layout = CatLayout::where('category_id', $user_id)->first();

        return response()->json(['status' => true, 'data' => $layout], 200);
    }


    public function getEventFields($eventId)
    {
        try {

            $event = Event::select('id', 'category')->find($eventId);

            if (!$event) {
                return response()->json([
                    'status' => false,
                    'message' => 'Event not found.'
                ], 404);
            }

            $categoryId = $event->category;

            $fieldIds = Catrgoty_has_Field::where('category_id', $categoryId)
                ->pluck('custom_fields_id')
                ->filter(function ($ids) {
                    return !empty($ids);
                })
                ->flatMap(function ($ids) {
                    return explode(',', $ids);
                })
                ->filter(function ($id) {
                    return !empty(trim($id));
                })
                ->map(function ($id) {
                    return (int) trim($id);
                })
                ->filter(function ($id) {
                    return $id > 0;
                })
                ->unique()
                ->values()
                ->toArray();

            $fields = !empty($fieldIds)
                ? CustomField::whereIn('id', $fieldIds)
                ->select('id', 'lable', 'field_name')
                ->get()
                : collect();

            return response()->json([
                'status' => true,
                'event_id' => $eventId,
                'category_id' => $categoryId,
                'fields' => $fields
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

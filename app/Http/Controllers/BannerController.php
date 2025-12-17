<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BannerController extends Controller
{

    public function allBanners()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Get all banners for Admin, else only banners created by the user
        if ($user->getRoleNames()->contains('Admin')) {
            $banners = Banner::with(['category'])
                ->orderBy('created_at', 'desc')
                ->get();
        } else {
            $banners = Banner::with(['category'])
                ->where('org_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        if ($banners->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'No banners found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data'   => $banners
        ], 200);
    }

    public function index(Request $request, $type)
    {
        $id = $request->query('id'); // always one query param: id

        $query = Banner::query();

        if ($type) {
            $query->where('type', $type);
        }

        // handle type-specific logic
        if ($type === 'category' && $id) {
            $category = Category::select('id', 'title')->find($id);

            if ($category) {
                $query->where('category', $category->id);
            } else {
                return response()->json([
                    'status'  => false,
                    'message' => 'Category not found',
                ], 200);
            }
        }

        if ($type === 'organisation' && $id) {
            $query->whereHas('event.user', function ($q) use ($id) {
                $q->where('id', $id);
            });
        }

        $query->with([
            'event:id,name,user_id,venue_id',
            'event.user:id,organisation',
            'event.venue:id,city,name',
            'category:id,title',
        ]);
        $banners = $query->get();

        if ($banners->isEmpty()) {
            return response()->json([
                'status'  => false,
                'message' => 'Banner not found',
            ], 200);
        }

        return response()->json([
            'status' => true,
            'data'   => $banners,
        ], 200);
    }
    public function store(Request $request)
    {
        try {
            // return response()->json($request->all());
            $maxSrNo = Banner::max('sr_no');
            $srNo = $maxSrNo ? $maxSrNo + 1 : 1;

            $bannerData = new Banner();
            $bannerData->sr_no = $srNo;
            $bannerData->type = $request->type;
            $bannerData->org_id = $request->org_id;
            $bannerData->category = $request->category;
            $bannerData->title = $request->title;
            $bannerData->description = $request->description;
            $bannerData->sub_description = $request->sub_description;
            $bannerData->button_link = $request->button_link;
            $bannerData->button_text = $request->button_text;
            $bannerData->external_url = $request->external_url;
            $bannerData->event_id = $request->event_id;
            $bannerData->event_key = $request->event_key;
            $bannerData->display_in_popup = $request->display_in_popup ?? 0;
            $bannerData->media_url = $request->media_url;

            if ($request->hasFile('images') && $request->file('images')->isValid()) {
                $file = $request->file('images');
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                $folder = 'uploads/banners';
                $file->move(public_path($folder), $fileName);
                $imagePath = url($folder . '/' . $fileName);

                $bannerData->images = $imagePath;
            }
            if ($request->hasFile('sm_image') && $request->file('sm_image')->isValid()) {
                $file = $request->file('sm_image');
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                $folder = 'uploads/banners/sm_image';
                $file->move(public_path($folder), $fileName);
                $imagePath = url($folder . '/' . $fileName);

                $bannerData->sm_image = $imagePath;
            }
            if ($request->hasFile('md_image') && $request->file('md_image')->isValid()) {
                $file = $request->file('md_image');
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                $folder = 'uploads/banners/md_image';
                $file->move(public_path($folder), $fileName);
                $imagePath = url($folder . '/' . $fileName);

                $bannerData->md_image = $imagePath;
            }

            $bannerData->save();
            return response()->json(['status' => true, 'message' => 'bannerData craete successfully', 'data' => $bannerData,], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to bannerData '], 404);
        }
    }

    public function show(string $id)
    {
        $bannerData = Banner::find($id);

        if (!$bannerData) {
            return response()->json(['status' => false, 'message' => 'bannerData not found'], 200);
        }

        return response()->json(['status' => true, 'data' => $bannerData], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            // Fetch existing banner
            $bannerData = Banner::findOrFail($id);

            // Update all fields
            $bannerData->type = $request->type ?? $bannerData->type;
            $bannerData->org_id = $request->org_id ?? $bannerData->org_id;
            $bannerData->category = $request->category ?? $bannerData->category;
            $bannerData->title = $request->title ?? $bannerData->title;
            $bannerData->description = $request->description ?? $bannerData->description;
            $bannerData->sub_description = $request->sub_description ?? $bannerData->sub_description;
            $bannerData->button_link = $request->button_link ?? $bannerData->button_link;
            $bannerData->button_text = $request->button_text ?? $bannerData->button_text;
            $bannerData->external_url = $request->external_url ?? $bannerData->external_url;
            $bannerData->event_id = $request->event_id ?? $bannerData->event_id;
            $bannerData->event_key = $request->event_key ?? $bannerData->event_key;
            $bannerData->display_in_popup = $request->display_in_popup ?? $bannerData->display_in_popup;
            $bannerData->media_url = $request->media_url ?? $bannerData->media_url;

            // Update Main Image
            if ($request->hasFile('images') && $request->file('images')->isValid()) {
                $file = $request->file('images');
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                $folder = 'uploads/banners';
                $file->move(public_path($folder), $fileName);
                $imagePath = url($folder . '/' . $fileName);

                $bannerData->images = $imagePath;
            }

            // Update Small Image
            if ($request->hasFile('sm_image') && $request->file('sm_image')->isValid()) {
                $file = $request->file('sm_image');
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                $folder = 'uploads/banners/sm_image';
                $file->move(public_path($folder), $fileName);
                $imagePath = url($folder . '/' . $fileName);

                $bannerData->sm_image = $imagePath;
            }

            // Update Medium Image
            if ($request->hasFile('md_image') && $request->file('md_image')->isValid()) {
                $file = $request->file('md_image');
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                $folder = 'uploads/banners/md_image';
                $file->move(public_path($folder), $fileName);
                $imagePath = url($folder . '/' . $fileName);

                $bannerData->md_image = $imagePath;
            }

            $bannerData->save();

            return response()->json([
                'status' => true,
                'message' => 'Banner updated successfully',
                'data' => $bannerData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update banner',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function destroy(string $id)
    {
        $bannerData = Banner::where('id', $id)->firstOrFail();
        if (!$bannerData) {
            return response()->json(['status' => false, 'message' => 'bannerData not found'], 200);
        }

        $bannerData->delete();
        return response()->json(['status' => true, 'message' => 'bannerData deleted successfully'], 200);
    }

    public function rearrangeBanner(Request $request, $type)
    {
        try {
            $srNoCount = [];

            foreach ($request->data as $item) {
                if (isset($srNoCount[$item['sr_no']])) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Duplicate sr_no values detected: ' . $item['sr_no'],
                    ], 400);
                }
                $srNoCount[$item['sr_no']] = true;
            }

            foreach ($request->data as $item) {
                $bannerData = Banner::where('type', $type)->findOrFail($item['id']);
                $bannerData->sr_no = $item['sr_no'];
                $bannerData->save();
            }

            $updatedBannerData = Banner::where('type', $type)->orderBy('sr_no')->get();

            return response()->json([
                'status' => true,
                'message' => 'Banner data rearranged successfully',
                'data' => $updatedBannerData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to rearrange banner data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }
}

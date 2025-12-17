<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Venue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VanueController extends Controller
{
    public function index($id)
    {
        // ID thi user fetch karo
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        $roles = $user->getRoleNames();

        // Admin check
        if ($roles->contains('Admin')) {
            $venues = Venue::with('user:id,name,organisation')->orderBy('created_at', 'desc')->get();
        }
        // Organizer check
        else if ($roles->contains('Organizer')) {
            $venues = Venue::with('user:id,name,organisation')->where('org_id', $user->id)->orderBy('created_at', 'desc')->get();
        } else {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // ✅ Convert venue_images JSON string to array
        $venues->transform(function ($venue) {
            // JSON string ne array ma decode kari, pachhi comma-separated string banavo
            $venue_images = $venue->venue_images ? json_decode($venue->venue_images, true) : [];
            $venue->venue_images = implode(',', $venue_images); // comma-separated string
            $venue->organisation = $venue->user->organisation ?? 'N/A';
            unset($venue->user);
            return $venue;
        });


        return response()->json([
            'status' => true,
            'data' => $venues
        ], 200);
    }

    public function venusData()
    {
        // ID thi user fetch karo
        $venues = Venue::with('user:id,name,organisation')->latest()->get();
        if ($venues->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'venues not found'
            ], 200);
        }
        return response()->json([
            'status' => true,
            'data' => $venues,
        ], 200);
    }
    public function store(Request $request)
    {
        try {
            $this->authorize('create', [Venue::class, $request->org_id]);
            $vanueData = new Venue();
            $vanueData->org_id = $request->org_id;
            $vanueData->name = $request->name;
            $vanueData->address = $request->address;
            $vanueData->city = $request->city;
            $vanueData->state = $request->state;
            $vanueData->type = $request->type;
            $vanueData->aembeded_code = $request->aembeded_code;
            $vanueData->map_url = $request->map_url;

            $imagesArray = []; // store all image URLs

            // ✅ handle multiple files safely
            $files = $request->file('venue_images');
            if ($files) {
                if (!is_array($files)) {
                    $files = [$files]; // normalize single file to array
                }
                $folder = 'uploads/venus';
                foreach ($files as $file) {
                    if ($file && $file->isValid()) {
                        $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                        $file->move(public_path($folder), $fileName);
                        $imagesArray[] = url($folder . '/' . $fileName);
                    }
                }
            }

            $vanueData->venue_images = json_encode($imagesArray);

            // single thumbnail
            if ($request->hasFile('thumbnail') && $request->file('thumbnail')->isValid()) {
                $file = $request->file('thumbnail');
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                $folder = 'uploads/venus/thumbnail';
                $file->move(public_path($folder), $fileName);
                $vanueData->thumbnail = url($folder . '/' . $fileName);
            }

            $vanueData->save();

            return response()->json([
                'status' => true,
                'message' => 'Venue created successfully',
                'data' => $vanueData,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to create venue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            // 1️⃣ Fetch existing venue
            $venue = Venue::findOrFail($id);
            $this->authorize('update', arguments: $venue);
            // 2️⃣ Update text fields
            $venue->name = $request->name ?? $venue->name;
            $venue->org_id = $request->org_id ?? $venue->org_id;
            $venue->address = $request->address ?? $venue->address;
            $venue->city = $request->city ?? $venue->city;
            $venue->state = $request->state ?? $venue->state;
            $venue->type = $request->type ?? $venue->type;
            $venue->aembeded_code = $request->aembeded_code ?? $venue->aembeded_code;
            $venue->map_url = $request->map_url ?? $venue->map_url;

            // 3️⃣ Handle existing images from DB
            $dbImages = [];
            if (!empty($venue->venue_images)) {
                $decoded = json_decode($venue->venue_images, true);
                if (is_array($decoded)) {
                    $dbImages = $decoded;
                }
            }

            // 4️⃣ Handle images coming from payload (these are to be kept)
            $payloadExisting = $request->input('existing_images', []);
            if (!is_array($payloadExisting)) {
                $payloadExisting = []; // normalize
            }

            // 5️⃣ Calculate images to delete (present in DB but not in payload)
            $imagesToDelete = array_diff($dbImages, $payloadExisting);
            foreach ($imagesToDelete as $imgUrl) {
                // optional: delete from storage
                $path = str_replace(url('/') . '/', '', $imgUrl);
                $fullPath = public_path($path);
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }

            // 6️⃣ Keep only images which frontend sent as existing
            $finalImages = $payloadExisting;

            // 7️⃣ Handle new file uploads
            $files = $request->file('venue_images');
            if ($files) {
                if (!is_array($files)) {
                    $files = [$files];
                }
                $folder = 'uploads/venus';
                foreach ($files as $file) {
                    if ($file && $file->isValid()) {
                        $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                        $file->move(public_path($folder), $fileName);
                        $finalImages[] = url($folder . '/' . $fileName);
                    }
                }
            }

            // 8️⃣ Save final images list (after remove + add new)
            $venue->venue_images = !empty($finalImages) ? json_encode(array_values($finalImages)) : null;

            // 9️⃣ Handle thumbnail
            if ($request->hasFile('thumbnail') && $request->file('thumbnail')->isValid()) {
                $file = $request->file('thumbnail');
                $folder = 'uploads/venus/thumbnail';
                $fileName = 'get-your-ticket-' . uniqid() . '-' . $file->getClientOriginalName();
                $file->move(public_path($folder), $fileName);
                $venue->thumbnail = url($folder . '/' . $fileName);
            }

            $venue->save();

            return response()->json([
                'status' => true,
                'message' => 'Venue updated successfully',
                'data' => $venue,
            ], 200);
        } catch (AuthorizationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to update this venue.',
                'error' => 'Unauthorized',
                'full_error' => $e->getMessage(),
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update venue',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(string $id)
    {
        $vanueData = Venue::find($id);

        if (!$vanueData) {
            return response()->json(['status' => false, 'message' => 'vanueData not found'], 200);
        }

        return response()->json(['status' => true, 'data' => $vanueData], 200);
    }

    public function destroy(string $id)
    {
        $venueData = Venue::where('id', $id)->firstOrFail();
        // Authorize the action
        $this->authorize('delete', $venueData);

        if (!$venueData) {
            return response()->json(['status' => false, 'message' => 'venueData not found'], 200);
        }

        $venueData->delete();
        return response()->json(['status' => true, 'message' => 'venueData deleted successfully'], 200);
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }
}

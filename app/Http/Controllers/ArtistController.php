<?php

namespace App\Http\Controllers;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Http\Request;
use Storage;

class ArtistController extends Controller
{

    public function index($id)
    {

        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        $roles = $user->getRoleNames();

        if ($roles->contains('Admin')) {
            $artists = Artist::orderBy('created_at', 'desc')->get();
        } else {
            $artists = Artist::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        if ($artists->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'Artist not found'
            ], 200);
        }

        return response()->json([
            'status' => true,
            'data'   => $artists
        ], 200);
    }

    public function artistsData()
    {

        $artists = Artist::get();
        if ($artists->isEmpty()) {
            return response()->json([
                'status' => false,
                'message' => 'venues not found'
            ], 200);
        }
        return response()->json([
            'status' => true,
            'data' => $artists,
        ], 200);
    }


    public function store(Request $request)
    {
        try {

            $artistsData = new Artist();
            $artistsData->name = $request->name;
            $artistsData->description = $request->description;
            $artistsData->category = $request->category;
            $artistsData->type = $request->type;
            $artistsData->event_id = $request->event_id;
            $artistsData->user_id = $request->user_id;
            $folder = 'Artist/photo/' . $request->name;

            if ($request->hasFile('photo')) {
                $artistsData->photo = $this->storeFile($request->file('photo'), $folder);
            }

            $artistsData->save();
            return response()->json(['status' => true, 'message' => 'ArtistData craete successfully', 'data' => $artistsData,], 200);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Failed to ArtistData '], 404);
        }
    }

    public function show($id)
    {
        $artistsData = Artist::find($id);

        if (!$artistsData) {
            return response()->json(['status' => false, 'message' => 'Artist not found'], 404);
        }

        return response()->json(['status' => true, 'data' => $artistsData], 200);
    }

    public function update(Request $request, $id)
    {
        try {
            $artistData = Artist::findOrFail($id);

            $artistData->name = $request->name ?? $artistData->name;
            $artistData->description = $request->description ?? $artistData->description;
            $artistData->category = $request->category ?? $artistData->category;
            $artistData->type = $request->type ?? $artistData->type;
            $artistData->event_id = $request->event_id ?? $artistData->event_id;
            $artistData->user_id = $request->user_id ?? $artistData->user_id;

            $folder = 'Artist/photo/' . $artistData->name;

            if ($request->hasFile('photo') && $request->file('photo')->isValid()) {

                if (!empty($artistData->photo)) {

                    if (\Storage::disk('public')->exists($artistData->photo)) {
                        \Storage::disk('public')->delete($artistData->photo);
                    }

                    $oldFile = public_path($artistData->photo);
                    if (file_exists($oldFile)) {
                        unlink($oldFile);
                    }
                }

                $artistData->photo = $this->storeFile($request->file('photo'), $folder);
            }

            $artistData->save();

            return response()->json([
                'status' => true,
                'message' => 'ArtistData updated successfully',
                'data' => $artistData
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update ArtistData',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    public function destroy(string $id)
    {
        $artistsData = Artist::where('id', $id)->firstOrFail();
        if (!$artistsData) {
            return response()->json(['status' => false, 'message' => 'Artist not found'], 404);
        }

        $artistsData->delete();
        return response()->json(['status' => true, 'message' => 'Artist deleted successfully'], 200);
    }

    private function storeFile($file, $folder, $disk = 'public')
    {
        if (!$file) return null;

        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('uploads/' . $folder, $filename, $disk);
        return Storage::disk($disk)->url($path);
    }
}

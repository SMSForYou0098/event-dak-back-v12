<?php

namespace App\Http\Controllers;

use App\Models\ShortUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShortUrlController extends Controller
{
    public function create(Request $request)
    {
        $request->validate([
            'url' => 'required|url'
        ]);

        $originalUrl = $request->url;

        // Generate unique short code
        do {
            $shortCode = Str::random(6); // alphanumeric, 6 chars
        } while (ShortUrl::where('short_url', $shortCode)->exists());

        // Build full short URL
        $shortUrlFull = 'https://gyt.co.in/s/' . $shortCode;

        // Save full short URL in DB
        $shortUrl = ShortUrl::create([
            'long_url' => $originalUrl,
            'short_url' => $shortUrlFull // store full URL here
        ]);

        return response()->json([
            'status' => true,
            'original_url' => $originalUrl,
            'short_url' => $shortUrlFull
        ], 200);
    }

    public function getLongUrl($url)
    {
        $ShortUrl = ShortUrl::where('short_url', $url)->first();

        if (!$ShortUrl) {
            return response()->json(['status' => false, 'message' => 'ShortUrl not found'], 200);
        }
        return response()->json([
            'status' => true,
            'message' => 'ShortUrl successfully',
            'data' => $ShortUrl
        ], 200);
    }

    public function redirectUrl($shortCode)
    {
        $shortUrl = ShortUrl::where('short_url', 'like', '%' . $shortCode)->first();
        //  return $shortUrl;
        // Stop execution and return JSON if not found
        if (!$shortUrl) {
            return response()->json([
                'status' => false,
                'message' => 'Short URL not found'
            ], 404);
        }

        // If found, redirect browser to the long URL
        return redirect()->away($shortUrl->long_url);
    }
}

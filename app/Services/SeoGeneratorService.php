<?php
// app/Services/SeoGeneratorService.php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SeoGeneratorService
{
    protected ?string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';
    protected string $model = 'gemini-2.5-flash';

    public function __construct()
    {
        $this->apiKey = config('gemini.api_key');
    }

    public function generateEventSeo(array $eventData): array
    {
        // Return fallback if API key is not configured
        if (empty($this->apiKey)) {
            Log::warning('Gemini API key not configured, using fallback SEO');
            return $this->getFallbackSeo($eventData);
        }

        $prompt = $this->buildPrompt($eventData);

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->baseUrl}/models/{$this->model}:generateContent", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            if ($response->failed()) {
                //Log::error('Gemini API error: ' . $response->body());
                return $this->getFallbackSeo($eventData);
            }

            $text = $response->json('candidates.0.content.parts.0.text');

            return $this->parseResponse($text, $eventData);
        } catch (\Exception $e) {
            // Log::error('Gemini API exception: ' . $e->getMessage());
            return $this->getFallbackSeo($eventData);
        }
    }

    protected function buildPrompt(array $eventData): string
    {
        $name = $eventData['name'] ?? '';
        $description = $eventData['description'] ?? '';
        $date = $eventData['date'] ?? '';
        $location = $eventData['location'] ?? '';
        $city = $eventData['city'] ?? '';
        $organisation = $eventData['organisation'] ?? '';
        $category = $eventData['category'] ?? '';

        return <<<PROMPT
You are an expert SEO specialist for "Get Your Ticket" - India's premier online event ticketing platform (similar to BookMyShow). Generate highly optimized SEO content for the following event listing.

BRAND CONTEXT:
- Platform Name: Get Your Ticket
- Website: getyourticket.in
- Industry: Online Event Ticketing & Entertainment
- Target Audience: Indian users looking to book tickets for events, concerts, shows, and experiences

EVENT DETAILS:
- Event Name: {$name}
- Description: {$description}
- Date: {$date}
- Venue/Location: {$location}
- City: {$city}
- Organizer: {$organisation}
- Category: {$category}

SEO REQUIREMENTS:
1. seo_title: Include event name + city + "Book Tickets | Get Your Ticket" (50-60 chars max)
2. meta_description: Compelling description with call-to-action, mention booking, include key details (150-160 chars)
3. keywords: 8-10 relevant keywords including event name, city, category, "book tickets", "get your ticket", related terms
4. og_title: Engaging title for social sharing (60-90 chars)
5. og_description: Social-friendly description that drives clicks (100-200 chars)
6. twitter_title: Concise title for Twitter cards (50-70 chars)
7. twitter_description: Engaging Twitter-specific description (100-150 chars)
8. slug: URL-friendly slug (lowercase, hyphens, no special chars)
9. focus_keyword: Primary keyword phrase for this event
10. schema_event_type: Appropriate schema.org event type (e.g., MusicEvent, TheaterEvent, SportsEvent, Festival, etc.)

Return ONLY valid JSON with no markdown, code blocks, or extra text:
{
    "seo_title": "Event Name City - Book Tickets | Get Your Ticket",
    "meta_description": "Book tickets for Event Name in City. Date details. Secure your seats now on Get Your Ticket!",
    "keywords": ["keyword1", "keyword2", "keyword3", "keyword4", "keyword5", "keyword6", "keyword7", "keyword8"],
    "og_title": "Event Name - Book Now on Get Your Ticket",
    "og_description": "Don't miss Event Name! Book your tickets now for an unforgettable experience.",
    "twitter_title": "Event Name - Tickets Available Now!",
    "twitter_description": "Book tickets for Event Name. Limited seats available!",
    "slug": "event-name-city-date",
    "focus_keyword": "event name city tickets",
    "schema_event_type": "MusicEvent"
}
PROMPT;
    }

    protected function parseResponse(string $text, array $eventData): array
    {
        // Remove markdown code blocks if present
        $text = preg_replace('/```json\s*|\s*```/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            //Log::warning('Failed to parse Gemini response: ' . $text);
            return $this->getFallbackSeo($eventData);
        }

        // Ensure slug is properly formatted
        $parsed['slug'] = Str::slug($parsed['slug'] ?? $eventData['name']);

        return $parsed;
    }

    protected function getFallbackSeo(array $eventData): array
    {
        $name = $eventData['name'] ?? 'Event';
        $description = $eventData['description'] ?? '';
        $city = $eventData['city'] ?? $eventData['location'] ?? '';
        $category = $eventData['category'] ?? 'Event';

        $seoTitle = Str::limit("{$name} {$city} - Book Tickets | Get Your Ticket", 60);
        $slug = Str::slug($name . ($city ? "-{$city}" : ''));

        return [
            'seo_title' => $seoTitle,
            'meta_description' => Str::limit("Book tickets for {$name}" . ($city ? " in {$city}" : '') . ". {$description} Secure your seats now on Get Your Ticket!", 160),
            'keywords' => array_filter([
                strtolower($name),
                strtolower($city),
                strtolower($category),
                'book tickets',
                'get your ticket',
                'event tickets',
                strtolower($category) . ' tickets',
                'online booking'
            ]),
            'og_title' => Str::limit("{$name} - Book Now on Get Your Ticket", 90),
            'og_description' => Str::limit("Don't miss {$name}! Book your tickets now for an unforgettable experience. {$description}", 200),
            'twitter_title' => Str::limit("{$name} - Tickets Available Now!", 70),
            'twitter_description' => Str::limit("Book tickets for {$name}. Limited seats available on Get Your Ticket!", 150),
            'slug' => $slug,
            'focus_keyword' => strtolower("{$name} {$city} tickets"),
            'schema_event_type' => $this->guessSchemaEventType($category),
        ];
    }

    protected function guessSchemaEventType(string $category): string
    {
        $category = strtolower($category);

        return match (true) {
            str_contains($category, 'music') || str_contains($category, 'concert') => 'MusicEvent',
            str_contains($category, 'theater') || str_contains($category, 'theatre') || str_contains($category, 'drama') => 'TheaterEvent',
            str_contains($category, 'sport') || str_contains($category, 'cricket') || str_contains($category, 'football') => 'SportsEvent',
            str_contains($category, 'festival') || str_contains($category, 'fair') => 'Festival',
            str_contains($category, 'comedy') || str_contains($category, 'standup') => 'ComedyEvent',
            str_contains($category, 'dance') => 'DanceEvent',
            str_contains($category, 'food') || str_contains($category, 'culinary') => 'FoodEvent',
            str_contains($category, 'education') || str_contains($category, 'workshop') || str_contains($category, 'seminar') => 'EducationEvent',
            str_contains($category, 'business') || str_contains($category, 'conference') => 'BusinessEvent',
            str_contains($category, 'exhibition') || str_contains($category, 'expo') => 'ExhibitionEvent',
            default => 'Event',
        };
    }
}

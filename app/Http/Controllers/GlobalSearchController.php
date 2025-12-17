<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\User;

class GlobalSearchController extends Controller
{

    public function search(Request $request)
    {
        $query = Event::query()
            ->select('id', 'name', 'category', 'event_type', 'user_id', 'event_key', 'venue_id', 'date_range', 'entry_time', 'start_time', 'end_time')
            ->with([
                'eventMedia' => function ($q) {
                    $q->select('event_id', 'thumbnail');
                },
                'venueEvent' => function ($q) {
                    $q->select('org_id', 'city', 'state', 'address'); // Make sure 'city' exists in Venue table
                },
                'organizer:id,name,organisation',
                'categoryDatanew:id,title',
                'eventControls:id,event_id,status',
                'tickets:id,event_id,price,sale'
            ]);


        $hasCategory = $request->filled('event_category');
        $hasSearch   = $request->has('search') && trim($request->search) !== '';

        // Stopwords to ignore
        $stopwords = ['in', 'the', 'a', 'an', 'of', 'and', 'for', 'on', 'at'];

        if ($hasCategory || $hasSearch) {
            $query->where(function ($q) use ($request, $hasCategory, $hasSearch, $stopwords) {

                // ✅ Category filter
                if ($hasCategory) {
                    $categoryNames = array_map('trim', explode(',', $request->event_category));
                    $categoryIds = Category::whereIn('title', $categoryNames)->pluck('id');

                    if ($categoryIds->count() > 0) {
                        $q->orWhereIn('category', $categoryIds);
                    }
                }

                // ✅ Search filter
                if ($hasSearch) {
                    $keywords = array_filter(explode(' ', trim($request->search)));

                    $q->orWhere(function ($sq) use ($keywords, $stopwords) {
                        foreach ($keywords as $word) {
                            $word = strtolower(trim($word));
                            if ($word === '' || in_array($word, $stopwords)) continue;

                            // 🗓️ TODAY → events happening today
                            if (in_array($word, ['live', 'liveevent', 'live-event', 'eventlive', 'live events', 'events live', 'today', 'happening today'])) {
                                $sq->orWhere(function ($liveQ) {
                                    $liveQ->whereRaw("
                                                        (
                                                            CASE 
                                                                WHEN date_range LIKE '%,%' THEN 
                                                                    STR_TO_DATE(SUBSTRING_INDEX(date_range, ',', 1), '%Y-%m-%d') <= CURDATE()
                                                                    AND STR_TO_DATE(SUBSTRING_INDEX(date_range, ',', -1), '%Y-%m-%d') >= CURDATE()
                                                                ELSE 
                                                                    STR_TO_DATE(date_range, '%Y-%m-%d') = CURDATE()
                                                            END
                                                        )
                                                    ")
                                        ->whereHas('eventControls', function ($ctrlQ) {
                                            $ctrlQ->where('status', "1");   // ✅ ACTIVE EVENTS ONLY
                                        });
                                });

                                continue;
                            }


                            // 🟠 OFFER / SALE
                            if (in_array($word, ['offer', 'offers', 'sale', 'sales'])) {
                                $sq->orWhere(function ($offerQ) {
                                    // 🔹 Offer date: active or upcoming
                                    $offerQ->where(function ($dateQ) {
                                        $dateQ->whereRaw("
                                        (
                                            CASE 
                                                WHEN date_range LIKE '%,%' THEN 
                                                    STR_TO_DATE(SUBSTRING_INDEX(date_range, ',', -1), '%Y-%m-%d') >= CURDATE()
                                                ELSE 
                                                    STR_TO_DATE(date_range, '%Y-%m-%d') >= CURDATE()
                                            END
                                        )
                                    ");
                                    })
                                        // 🔹 Tickets: sale = 1 and active or upcoming sale_date
                                        ->whereHas('tickets', function ($ticketQ) {
                                            $ticketQ->where('sale', 1)
                                                ->where(function ($ticketDateQ) {
                                                    $ticketDateQ->whereRaw("
                                        (
                                            CASE 
                                                WHEN sale_date LIKE '%,%' THEN 
                                                    STR_TO_DATE(SUBSTRING_INDEX(sale_date, ',', -1), '%Y-%m-%d') >= CURDATE()
                                                ELSE 
                                                    STR_TO_DATE(sale_date, '%Y-%m-%d') >= CURDATE()
                                            END
                                        )
                                            ");
                                                });
                                        });
                                });

                                continue;
                            }


                            // 🟣 FREE
                            if (in_array($word, ['free', 'free event', 'free events'])) {
                                $sq->orWhere(function ($offerQ) {
                                    // 🔹 Only active or upcoming events
                                    $offerQ->whereHas('eventControls', function ($ctrlQ) {
                                        $ctrlQ->where('status', 1);
                                    })
                                        ->where(function ($dateQ) {
                                            $dateQ->whereRaw("
                                                (
                                                    CASE 
                                                        WHEN date_range LIKE '%,%' THEN 
                                                            STR_TO_DATE(SUBSTRING_INDEX(date_range, ',', -1), '%Y-%m-%d') >= CURDATE()
                                                        ELSE 
                                                            STR_TO_DATE(date_range, '%Y-%m-%d') >= CURDATE()
                                                    END
                                                )
                                            ");
                                        })
                                        // 🔹 Free tickets only (price = 0)
                                        ->whereHas('tickets', function ($ticketQ) {
                                            $ticketQ->where('price', 0);
                                        });
                                });

                                continue;
                            }




                            // 🔹 Match in Event fields
                            $sq->orWhere('name', 'like', "%{$word}%")
                                ->orWhere('description', 'like', "%{$word}%");

                            // 🔹 Match in related Venue table
                            $sq->orWhereHas('venueEvent', function ($venueQ) use ($word) {
                                $venueQ->where('city', 'like', "%{$word}%")
                                    ->orWhere('state', 'like', "%{$word}%")
                                    ->orWhere('address', 'like', "%{$word}%");
                            });

                            // 🔹 Match in related User table (organizer)
                            $userIds = User::where('name', 'like', "%{$word}%")
                                ->orWhere('organisation', 'like', "%{$word}%")
                                ->pluck('id');

                            if ($userIds->count() > 0) {
                                $sq->orWhereIn('user_id', $userIds);
                            }
                        }
                    });
                }
            });
        }

        $events = $query->get();

        return response()->json([
            'status' => true,
            'data'   => $events
        ], 200);
    }
}

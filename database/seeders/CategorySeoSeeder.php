<?php

namespace Database\Seeders;

use App\Models\SeoConfig;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CategorySeoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    { {
            $seoData = [
                [
                    'type' => 'categories',
                    'item_id' => '1',
                    'category_name' => 'Business Seminars',
                    'meta_title' => 'Book Business Seminars & Networking Events Near You | Get Your Ticket',
                    'meta_description' => 'Discover and book top business seminars, workshops, and corporate events near you. Learn from industry leaders and grow your network.',
                    'meta_keyword' => 'business seminars, networking events, corporate workshops, business events',
                    'meta_tag' => 'business, seminars, networking, workshops, corporate events',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '2',
                    'category_name' => 'Live Band',
                    'meta_title' => 'Book Live Band Performances & Music Nights | Get Your Ticket',
                    'meta_description' => 'Explore and book live band shows and musical performances happening near you. Enjoy the rhythm and vibe with the best live music events.',
                    'meta_keyword' => 'live band, music nights, live music events, band shows',
                    'meta_tag' => 'live music, band performance, concerts, music night',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '3',
                    'category_name' => 'Live Concert',
                    'meta_title' => 'Find & Book Live Concert Tickets Near You | Get Your Ticket',
                    'meta_description' => 'Don’t miss your favorite artists! Book tickets for the hottest live concerts happening around you.',
                    'meta_keyword' => 'live concert tickets, music shows, artist events, live performances',
                    'meta_tag' => 'concerts, music events, live shows, music artists',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '4',
                    'category_name' => 'DJ Night',
                    'meta_title' => 'Book DJ Night Events & Club Parties Near You | Get Your Ticket',
                    'meta_description' => 'Dance the night away with electrifying DJ nights and clubbing events near you. Book your entry now!',
                    'meta_keyword' => 'DJ night events, club parties, EDM nights, nightlife events',
                    'meta_tag' => 'DJ, clubbing, nightlife, party events',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '5',
                    'category_name' => 'Garba Night',
                    'meta_title' => 'Book Garba Night & Dandiya Events Near You | Get Your Ticket',
                    'meta_description' => 'Celebrate Navratri with colorful Garba and Dandiya nights. Book your passes for the biggest traditional dance festivals near you.',
                    'meta_keyword' => 'Garba night, Dandiya events, Navratri celebration, traditional dance',
                    'meta_tag' => 'Garba, Dandiya, Navratri, dance events',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '6',
                    'category_name' => 'Food Festival',
                    'meta_title' => 'Explore & Book Food Festivals Happening Near You | Get Your Ticket',
                    'meta_description' => 'Indulge your taste buds at the best food festivals in town. Book tickets for culinary events featuring top chefs and local delicacies.',
                    'meta_keyword' => 'food festival, culinary events, food tasting, gourmet events',
                    'meta_tag' => 'food, festival, culinary, gastronomy, gourmet',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '7',
                    'category_name' => 'Education Festival',
                    'meta_title' => 'Book Educational Fairs & Student Events | Get Your Ticket',
                    'meta_description' => 'Attend education festivals featuring top institutions, workshops, and career guidance. Book your spot at the best learning events.',
                    'meta_keyword' => 'education festival, student fairs, career events, academic expos',
                    'meta_tag' => 'education, learning, students, career, academic',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '8',
                    'category_name' => 'Techno Fair',
                    'meta_title' => 'Book Techno Fairs & Tech Innovation Events | Get Your Ticket',
                    'meta_description' => 'Discover cutting-edge technology at techno fairs and innovation expos. Book your pass for tech showcases and future tech trends.',
                    'meta_keyword' => 'techno fair, tech events, innovation expo, tech exhibitions',
                    'meta_tag' => 'technology, innovation, techno fair, gadgets, future tech',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '9',
                    'category_name' => 'Real Estate',
                    'meta_title' => 'Attend Real Estate Expos & Property Shows | Get Your Ticket',
                    'meta_description' => 'Explore investment opportunities at real estate expos and property fairs near you. Book your entry and connect with developers.',
                    'meta_keyword' => 'real estate expo, property shows, home buying events, realty fairs',
                    'meta_tag' => 'real estate, property, housing, investment, realty',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '10',
                    'category_name' => 'Fun Fair',
                    'meta_title' => 'Book Tickets for Local Fun Fairs & Family Events | Get Your Ticket',
                    'meta_description' => 'Enjoy a day full of fun, games, and rides at exciting fun fairs happening near you. Great for kids and families.',
                    'meta_keyword' => 'fun fair, family events, amusement fairs, kids activities',
                    'meta_tag' => 'fun fair, family, kids, amusement, games',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '11',
                    'category_name' => 'Amusement',
                    'meta_title' => 'Explore Amusement Parks & Adventure Events | Get Your Ticket',
                    'meta_description' => 'Book thrilling amusement events and adventure activities for all age groups. Make memories with fun rides and attractions.',
                    'meta_keyword' => 'amusement events, adventure parks, family fun, thrill rides',
                    'meta_tag' => 'amusement, thrill, adventure, family fun, rides',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '12',
                    'category_name' => 'Tech Conferences',
                    'meta_title' => 'Book Tech Conferences & IT Summits Near You | Get Your Ticket',
                    'meta_description' => 'Join top tech minds at leading technology conferences and innovation summits. Book now for learning, networking, and growth.',
                    'meta_keyword' => 'tech conference, IT summits, innovation events, software expos',
                    'meta_tag' => 'technology, IT, conference, innovation, software',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '13',
                    'category_name' => 'Hello',
                    'meta_title' => 'Explore Trending Events Near You | Get Your Ticket',
                    'meta_description' => 'Discover trending and popular events in your city. From concerts to seminars, find it all here.',
                    'meta_keyword' => 'trending events, local happenings, live shows, top events',
                    'meta_tag' => 'events, trending, popular, city guide, shows',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '14',
                    'category_name' => 'Corporate',
                    'meta_title' => 'Book Corporate Events & Business Gatherings | Get Your Ticket',
                    'meta_description' => 'Organize or attend top corporate events, meetings, and business networking sessions. Book your tickets today.',
                    'meta_keyword' => 'corporate events, business gatherings, company meetings, professional events',
                    'meta_tag' => 'corporate, business, networking, events, professional',
                ],
                [
                    'type' => 'categories',
                    'item_id' => '15',
                    'category_name' => 'Day Garba',
                    'meta_title' => 'Book Day Garba & Cultural Dance Events | Get Your Ticket',
                    'meta_description' => 'Experience the joy of Day Garba with vibrant music and traditional dance. Book your tickets for festive daytime celebrations.',
                    'meta_keyword' => 'day Garba, cultural dance events, Navratri day events, Garba celebration',
                    'meta_tag' => 'Garba, dance, daytime events, culture, tradition',
                ],
            ];

            foreach ($seoData as $data) {
                SeoConfig::create($data);
            }
        }
    }
}

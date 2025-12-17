<?php

namespace App\Providers;

use App\Models\EmailConfig;
use Illuminate\Support\ServiceProvider;
use App\Channels\FirebaseChannel;
use App\Models\Artist;
use App\Models\Category;
use App\Models\Event;
use App\Models\Venue;
use App\Observers\ArtistObserver;
use App\Observers\CategoryObserver;
use App\Observers\EventObserver;
use App\Observers\VenueObserver;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Config;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Event Observer for cache invalidation
        Event::observe(EventObserver::class);
        Venue::observe(VenueObserver::class);
        Category::observe(CategoryObserver::class);
        Artist::observe(ArtistObserver::class);

        Notification::extend('firebase', function ($app) {
            return new FirebaseChannel();
        });

        // Skip email config loading during migrations or if table doesn't exist
        try {
            if (!Schema::hasTable('email_configs')) {
                return;
            }

            $emailConfig = EmailConfig::first();

            if ($emailConfig) {
                // Laravel 10+ mail configuration structure
                Config::set('mail.default', 'smtp');
                Config::set('mail.mailers.smtp.transport', 'smtp');
                Config::set('mail.mailers.smtp.host', $emailConfig->mail_host);
                Config::set('mail.mailers.smtp.port', $emailConfig->mail_port);
                Config::set('mail.mailers.smtp.username', $emailConfig->mail_username);
                Config::set('mail.mailers.smtp.password', $emailConfig->mail_password);
                Config::set('mail.mailers.smtp.encryption', $emailConfig->mail_encryption);
                Config::set('mail.from.address', $emailConfig->mail_from_address);
                Config::set('mail.from.name', $emailConfig->mail_from_name);
            }
        } catch (\Exception $e) {
            // Silently fail if table doesn't exist (e.g., during migrations)
        }
    }
}

<?php

namespace App\Providers;

use App\Contracts\MalwareScanner;
use App\Contracts\OtpSender;
use App\Models\Actor;
use App\Models\Message;
use App\Models\Need;
use App\Models\Order;
use App\Models\PortfolioItem;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\ClamAvScanner;
use App\Services\HttpOtpSender;
use App\Services\LogOtpSender;
use App\Services\NoopScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpSender::class, fn () => config('kariva.otp.driver') === 'http' ? new HttpOtpSender : new LogOtpSender);
        $this->app->bind(MalwareScanner::class, fn () => config('kariva.media.scanner') === 'clamav' ? new ClamAvScanner : new NoopScanner);
    }

    public function boot(): void
    {
        // One default limit for the whole API; sensitive routes add their own stricter ones.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));

        Route::model('item', PortfolioItem::class);

        // Polymorphic columns (media, reports, favorites) store these short names, never PHP class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'actor' => Actor::class,
            'service' => Service::class,
            'portfolio_item' => PortfolioItem::class,
            'order' => Order::class,
            'message' => Message::class,
            'verification_request' => VerificationRequest::class,
            'review' => Review::class,
            'need' => Need::class,
        ]);
    }
}

<?php

namespace App\Providers;

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
use App\Services\LogOtpSender;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OtpSender::class, LogOtpSender::class);
    }

    public function boot(): void
    {
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

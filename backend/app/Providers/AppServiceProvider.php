<?php

namespace App\Providers;

use App\Models\Actor;
use App\Models\Message;
use App\Models\Need;
use App\Models\Order;
use App\Models\PortfolioItem;
use App\Models\Review;
use App\Models\Service;
use App\Models\VerificationRequest;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Polymorphic columns (media, reports, favorites) store these short names, never PHP class names.
        Relation::enforceMorphMap([
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

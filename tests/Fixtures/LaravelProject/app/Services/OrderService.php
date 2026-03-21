<?php

namespace App\Services;

use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Shipping;
use App\Models\Discount;
use App\Models\Coupon;
use App\Models\Tax;
use App\Models\Notification;
use App\Models\Log;
use App\Models\Audit;

class OrderService
{
    public function processOrder(): void
    {
        // TODO: implement this properly
        // FIXME: this logic is broken
    }

    public function calculateTotal(): float
    {
        // HACK: temporary workaround
        return 0.0;
    }
}

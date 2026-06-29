<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class StockAlertEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public readonly array $alerts) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('inventory-alerts')];
    }

    public function broadcastAs(): string
    {
        return 'stock.alert';
    }
}

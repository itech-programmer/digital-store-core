<?php

namespace App\Services\Order;

use App\Contracts\Catalog\ProductRepositoryInterface;
use App\Contracts\Event\DomainEventRecorderInterface;
use App\Contracts\Order\OrderItemRepositoryInterface;
use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Order\OrderServiceInterface;
use App\Contracts\Payment\PaymentWebhookRepositoryInterface;
use App\Contracts\Payment\PaymentWebhookServiceInterface;
use App\DTO\Order\CreateOrderDto;
use App\DTO\Payment\PaymentWebhookDto;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService implements OrderServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderItemRepositoryInterface $orderItems,
        private readonly ProductRepositoryInterface $products,
        private readonly PaymentWebhookRepositoryInterface $webhooks,
        private readonly PaymentWebhookServiceInterface $paymentWebhook,
        private readonly DomainEventRecorderInterface $events,
    ) {}

    public function create(CreateOrderDto $dto): Order
    {
        if ($dto->items === []) {
            throw ValidationException::withMessages([
                'items' => ['Order must contain at least one item.'],
            ]);
        }

        $resolved = [];
        foreach ($dto->items as $index => $itemDto) {
            $product = $this->products->findActiveBySku($itemDto->sku);

            if ($product === null) {
                throw (new ModelNotFoundException)->setModel(Product::class, [$itemDto->sku]);
            }

            $resolved[] = [
                'product' => $product,
                'quantity' => max(1, $itemDto->quantity),
                'position' => $index,
            ];
        }

        return DB::transaction(function () use ($resolved, $dto) {
            $publicId = $dto->publicId ?: ('ord_'.Str::lower(Str::random(10)));

            if ($this->orders->findByPublicId($publicId) !== null) {
                throw ValidationException::withMessages([
                    'public_id' => ['Order id already exists.'],
                ]);
            }

            $amount = 0.0;
            $currency = $resolved[0]['product']->currency;

            foreach ($resolved as $row) {
                $product = $row['product'];
                $amount += (float) $product->price * (int) $row['quantity'];
            }

            $firstSku = $resolved[0]['product']->sku;

            $order = $this->orders->create([
                'public_id' => $publicId,
                'sku' => $firstSku,
                'amount' => $amount,
                'currency' => $currency,
                'status' => OrderStatus::Created,
            ]);

            $createdItems = [];
            foreach ($resolved as $row) {
                $product = $row['product'];

                $item = $this->orderItems->create([
                    'order_id' => $order->id,
                    'sku' => $product->sku,
                    'quantity' => $row['quantity'],
                    'unit_price' => $product->price,
                    'currency' => $product->currency,
                    'status' => OrderItemStatus::Pending,
                    'position' => $row['position'],
                    'supplier' => $product->preferred_supplier,
                ]);
                $createdItems[] = [
                    'id' => $item->id,
                    'sku' => $item->sku,
                    'unit_price' => (float) $item->unit_price,
                ];
            }

            $this->events->record('order', $order->id, 'order.created', [
                'public_id' => $order->public_id,
                'amount' => (float) $order->amount,
                'currency' => $order->currency,
                'items' => $createdItems,
            ]);

            $pending = $this->webhooks->listPendingByOrderPublicId($order->public_id);

            foreach ($pending as $pendingWebhook) {
                $this->paymentWebhook->process(
                    PaymentWebhookDto::fromValidated($pendingWebhook->payload)
                );
                $this->webhooks->deletePending($pendingWebhook);
            }

            return $this->orders->freshWithItems($order);
        });
    }

    public function findByPublicId(string $publicId): Order
    {
        $order = $this->orders->findByPublicId($publicId);

        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$publicId]);
        }

        return $this->orders->loadItemsOrdered($order);
    }
}

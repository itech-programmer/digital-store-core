<?php

namespace App\Services\Order;

use App\Contracts\Order\OrderRepositoryInterface;
use App\Contracts\Catalog\ProductRepositoryInterface;
use App\Contracts\Order\OrderServiceInterface;
use App\Contracts\Payment\PaymentWebhookServiceInterface;
use App\DTO\Order\CreateOrderDto;
use App\DTO\Payment\PaymentWebhookDto;
use App\Enums\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Order\Order;
use App\Models\Payment\PendingWebhook;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService implements OrderServiceInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly ProductRepositoryInterface $products,
        private readonly PaymentWebhookServiceInterface $paymentWebhook,
    ) {}

    public function create(CreateOrderDto $dto): Order
    {
        $product = $this->products->findActiveBySku($dto->sku);

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$dto->sku]);
        }

        return DB::transaction(function () use ($product, $dto) {
            $publicId = $dto->publicId ?: ('ord_'.Str::lower(Str::random(10)));

            if ($this->orders->findByPublicId($publicId) !== null) {
                throw ValidationException::withMessages([
                    'public_id' => ['Order id already exists.'],
                ]);
            }

            $order = new Order([
                'public_id' => $publicId,
                'sku' => $product->sku,
                'amount' => $product->price,
                'currency' => $product->currency,
                'status' => OrderStatus::Created,
            ]);

            $this->orders->save($order);

            $pending = PendingWebhook::query()
                ->where('order_public_id', $order->public_id)
                ->orderBy('received_at')
                ->get();

            foreach ($pending as $item) {
                $this->paymentWebhook->process(
                    PaymentWebhookDto::fromValidated($item->payload)
                );
                $item->delete();
            }

            return $order->fresh();
        });
    }

    public function findByPublicId(string $publicId): Order
    {
        $order = $this->orders->findByPublicId($publicId);

        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [$publicId]);
        }

        return $order;
    }
}

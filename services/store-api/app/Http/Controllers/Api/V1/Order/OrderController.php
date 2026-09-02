<?php

namespace App\Http\Controllers\Api\V1\Order;

use App\Contracts\Order\OrderServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderServiceInterface $orders,
    ) {}

    #[OA\Post(
        path: '/api/v1/orders',
        operationId: 'createOrder',
        description: 'Создает заказ по SKU. Если webhook пришел раньше заказа - pending события будут обработаны автоматически.',
        summary: 'Создать заказ',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/CreateOrderRequest'),
        ),
        tags: ['Orders'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Заказ создан',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'SKU не найден',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage'),
            ),
            new OA\Response(response: 422, description: 'Ошибка валидации'),
        ],
    )]
    public function store(CreateOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orders->create($request->toDto());
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'SKU not found',
            ], 404);
        }

        return OrderResource::make($order)
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/orders/{id}',
        operationId: 'getOrder',
        description: 'Возвращает статус заказа и выданный код (если delivered).',
        summary: 'Получить заказ',
        tags: ['Orders'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                description: 'Публичный ID заказа',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', example: 'ord_abc123xyz0'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Заказ найден',
                content: new OA\JsonContent(ref: '#/components/schemas/OrderResponse'),
            ),
            new OA\Response(
                response: 404,
                description: 'Заказ не найден',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage'),
            ),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        try {
            $order = $this->orders->findByPublicId($id);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Order not found',
            ], 404);
        }

        return OrderResource::make($order)->response();
    }
}

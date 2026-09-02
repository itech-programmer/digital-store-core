<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Contracts\Order\RecoveryServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class RecoveryController extends Controller
{
    public function __construct(
        private readonly RecoveryServiceInterface $recovery,
    ) {}

    #[OA\Post(
        path: '/api/v1/admin/recover',
        operationId: 'adminRecover',
        description: 'Сбрасывает stale delivering и повторяет выдачу для paid/out_of_stock/delivery_failed.',
        summary: 'Recovery зависших заказов',
        security: [['AdminToken' => []]],
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(
                name: 'stale_minutes',
                description: 'Через сколько минут delivering считается зависшим',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 10, example: 10),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Результат recovery',
                content: new OA\JsonContent(ref: '#/components/schemas/RecoveryResponse'),
            ),
            new OA\Response(
                response: 401,
                description: 'Неверный или отсутствующий X-Admin-Token',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage'),
            ),
        ],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $stale = (int) $request->integer('stale_minutes', 10);

        return response()->json($this->recovery->recoverStuck(max(1, $stale)));
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Contracts\Admin\PointInTimeServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderAsOfRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class OrderAsOfController extends Controller
{
    public function __construct(
        private readonly PointInTimeServiceInterface $pointInTime,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/orders/{id}/as-of',
        operationId: 'adminOrderAsOf',
        description: 'Восстанавливает состояние заказа на момент времени из domain_events + ledger (задача 4).',
        summary: 'Order as-of',
        security: [['AdminToken' => []]],
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'at', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Снимок на момент', content: new OA\JsonContent(ref: '#/components/schemas/OrderAsOfResponse')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
            new OA\Response(response: 422, description: 'Ошибка валидации'),
        ],
    )]
    public function __invoke(OrderAsOfRequest $request, string $id): JsonResponse
    {
        return response()->json($this->pointInTime->orderAsOf($id, $request->at()));
    }
}

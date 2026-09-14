<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Contracts\Order\RecoveryServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecoverStuckRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class RecoveryController extends Controller
{
    public function __construct(
        private readonly RecoveryServiceInterface $recovery,
    ) {}

    #[OA\Post(
        path: '/api/v1/admin/recover',
        operationId: 'adminRecover',
        description: 'Line-aware recovery: сбрасывает stale delivering и дожимает выдачу/refund.',
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
            new OA\Response(response: 422, description: 'Ошибка валидации'),
        ],
    )]
    public function __invoke(RecoverStuckRequest $request): JsonResponse
    {
        return response()->json($this->recovery->recoverStuck($request->staleMinutes()));
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Contracts\Admin\DeliveryProgressServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class DeliveryProgressController extends Controller
{
    public function __construct(
        private readonly DeliveryProgressServiceInterface $progress,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/delivery-progress',
        operationId: 'adminDeliveryProgress',
        description: 'Глубина очереди delivery и счётчики выдачи (задача 3).',
        summary: 'Прогресс выдачи',
        security: [['AdminToken' => []]],
        tags: ['Admin'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Снимок прогресса',
                content: new OA\JsonContent(ref: '#/components/schemas/DeliveryProgressResponse'),
            ),
            new OA\Response(
                response: 401,
                description: 'Неверный или отсутствующий X-Admin-Token',
                content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage'),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        return response()->json($this->progress->progress());
    }
}

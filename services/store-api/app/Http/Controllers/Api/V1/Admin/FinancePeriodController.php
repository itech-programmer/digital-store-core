<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Contracts\Admin\PointInTimeServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FinancePeriodRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class FinancePeriodController extends Controller
{
    public function __construct(
        private readonly PointInTimeServiceInterface $pointInTime,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/finance/period',
        operationId: 'adminFinancePeriod',
        description: 'Итоги financial_ledger за период (задача 4).',
        summary: 'Finance period totals',
        security: [['AdminToken' => []]],
        tags: ['Admin'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Итоги периода', content: new OA\JsonContent(ref: '#/components/schemas/FinancePeriodResponse')),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/ErrorMessage')),
            new OA\Response(response: 422, description: 'Ошибка валидации'),
        ],
    )]
    public function __invoke(FinancePeriodRequest $request): JsonResponse
    {
        return response()->json($this->pointInTime->financePeriod($request->from(), $request->to()));
    }
}

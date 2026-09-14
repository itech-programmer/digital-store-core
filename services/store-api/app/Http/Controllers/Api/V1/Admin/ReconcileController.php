<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Contracts\Payment\ReconcileServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class ReconcileController extends Controller
{
    public function __construct(
        private readonly ReconcileServiceInterface $reconcile,
    ) {}

    #[OA\Get(
        path: '/api/v1/admin/reconcile',
        operationId: 'adminReconcile',
        description: 'Находит расхождения статусов и проверяет инвариант paid = delivered + refunded в financial_ledger.',
        summary: 'Сверка ledger',
        security: [['AdminToken' => []]],
        tags: ['Admin'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Отчет сверки',
                content: new OA\JsonContent(ref: '#/components/schemas/ReconcileResponse'),
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
        return response()->json($this->reconcile->reconcile());
    }
}

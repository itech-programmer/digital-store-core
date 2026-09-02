<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Contracts\Payment\PaymentWebhookServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\PaymentWebhookRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use Throwable;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentWebhookServiceInterface $paymentWebhook,
    ) {}

    #[OA\Post(
        path: '/api/v1/webhook/payment',
        operationId: 'paymentWebhook',
        description: 'Обрабатывает событие paid/failed. Idempotent по event_id: повтор с тем же event_id не выдает код дважды.',
        summary: 'Платежный webhook',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/PaymentWebhookRequest'),
        ),
        tags: ['Payment'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Событие принято',
                content: new OA\JsonContent(ref: '#/components/schemas/WebhookAcceptedResponse'),
            ),
            new OA\Response(response: 422, description: 'Ошибка валидации'),
            new OA\Response(
                response: 503,
                description: 'Внутренняя ошибка обработки',
                content: new OA\JsonContent(
                    properties: [new OA\Property(property: 'status', type: 'string', example: 'error')],
                ),
            ),
        ],
    )]
    public function __invoke(PaymentWebhookRequest $request): JsonResponse
    {
        try {
            $this->paymentWebhook->process($request->toDto());
        } catch (Throwable $e) {
            Log::error('payment.webhook.error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 503);
        }

        return response()->json(['status' => 'accepted']);
    }
}

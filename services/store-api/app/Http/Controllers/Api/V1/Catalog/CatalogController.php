<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Contracts\Catalog\StockCacheServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CatalogController extends Controller
{
    public function __construct(
        private readonly StockCacheServiceInterface $stockCache,
    ) {}

    #[OA\Get(
        path: '/api/v1/catalog/stock',
        operationId: 'catalogStock',
        description: 'Пагинированный список активных SKU с остатком из product_stock_cache (без COUNT по product_keys на каждый запрос).',
        summary: 'Витрина: SKU и остатки',
        tags: ['Catalog'],
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1, example: 1),
            ),
            new OA\Parameter(
                name: 'per_page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 200, default: 100, example: 100),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Список SKU',
                content: new OA\JsonContent(ref: '#/components/schemas/CatalogStockResponse'),
            ),
        ],
    )]
    public function stock(Request $request): JsonResponse
    {
        $page = (int) $request->integer('page', 1);
        $perPage = (int) $request->integer('per_page', 100);

        $paginator = $this->stockCache->storefront($page, $perPage);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}

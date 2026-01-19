<?php

namespace MIIM\ModelContracting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use MIIM\ModelContracting\Services\ModelApiService;
use MIIM\ModelContracting\Services\ModelMetaService;

class ModelContractingController
{
    public function __construct(
        private ModelApiService $apiService,
        private ModelMetaService $metaService
    ) {}

    public function getMeta(Request $request, string $alias): JsonResponse
    {
        try {
            $metadata = $this->metaService->getModelMetadata($alias);
            return response()->json($metadata);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 404);
        }
    }

    public function index(Request $request, string $alias): JsonResponse
    {
        try {
            $params = [
                'pagination' => [
                    'page' => $request->input('pagination.page', 1),
                    'perPage' => $request->input('pagination.perPage', 10),
                ],
                'sort' => [
                    'field' => $request->input('sort.field'),
                    'order' => $request->input('sort.order', 'ASC'),
                ],
                'filter' => $request->input('filter', []),
            ];

            // Если передан параметр id
            $ids = $request->input('id');
            if ($ids) {
                $ids = is_array($ids) ? $ids : explode(',', $ids);
                $result = $this->apiService->read($alias, $ids, $params);
            } else {
                $result = $this->apiService->read($alias, null, $params);
            }

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function store(Request $request, string $alias): JsonResponse
    {
        try {
            $data = $request->validate([
                'data' => 'required|array',
            ]);

            $result = $this->apiService->create($alias, $data['data']);

            return response()->json($result, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function update(Request $request, string $alias): JsonResponse
    {
        try {
            $data = $request->validate([
                'ids' => 'required|array',
                'data' => 'required|array',
            ]);

            $this->apiService->update($alias, $data['ids'], $data['data']);

            return response()->json(null, 204);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $e->errors()
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function destroy(Request $request, string $alias): JsonResponse
    {
        try {
            $ids = $request->input('id');

            if (!$ids) {
                return response()->json([
                    'error' => 'Parameter id is required'
                ], 400);
            }

            $ids = is_array($ids) ? $ids : explode(',', $ids);
            $this->apiService->delete($alias, $ids);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }
}

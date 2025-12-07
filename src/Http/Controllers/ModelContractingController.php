<?php

namespace MIIM\ModelContracting\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use MIIM\ModelContracting\Services\ModelContractService;

class ModelContractingController
{
    protected ModelContractService $service;

    public function __construct(ModelContractService $service)
    {
        $this->service = $service;
    }

    public function getMeta(string $alias): JsonResponse
    {
        try {
            $meta = $this->service->getModelMeta($alias);
            return response()->json($meta);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function getInstances(Request $request, string $alias): JsonResponse
    {
        try {
            $ids = $this->service->parseIds($request->get('id'));
            $params = $this->service->prepareParamsFromRequest($request);

            $result = $this->service->getInstances($alias, $ids, $params);
            return response()->json($result);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function createInstance(Request $request, string $alias): JsonResponse
    {
        try {
            $data = $request->input('data', []);
            $instance = $this->service->createInstance($alias, $data);

            return response()->json(['data' => $instance], 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function updateInstances(Request $request, string $alias): JsonResponse
    {
        try {
            $ids = $request->input('ids', []);
            $data = $request->input('data', []);

            $this->service->updateInstances($alias, $ids, $data);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function deleteInstances(Request $request, string $alias): JsonResponse
    {
        try {
            $ids = $this->service->parseIds($request->get('id'));
            $this->service->deleteInstances($alias, $ids);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    protected function errorResponse(\Exception $e): JsonResponse
    {
        return response()->json([
            'error' => $e->getMessage()
        ], 400);
    }
}

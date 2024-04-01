<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    protected $HTTP_OK = JsonResponse::HTTP_OK;
    protected $HTTP_INTERNAL_SERVER_ERROR = JsonResponse::HTTP_INTERNAL_SERVER_ERROR;
    protected $response;

    public function __construct() {
        $this->response = response();
    }

    public function responseJson($data = null) : JsonResponse {
        return $this->response->json($data, $this->HTTP_OK);
    }

    public function responseJsonWithError($message, $error = JsonResponse::HTTP_CONFLICT) : JsonResponse {
        return $this->response->json([
            'error' => 1,
            'message' => $message
        ], $error);
    }

    public function responseJsonInternalError($message) : JsonResponse {
        return $this->responseJsonWithError($message, $this->HTTP_INTERNAL_SERVER_ERROR);
    }


}

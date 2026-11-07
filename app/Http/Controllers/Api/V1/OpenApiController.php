<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** The machine-readable description of the API. It is public: it contains no data and no secret. */
class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $document = json_decode((string) file_get_contents(resource_path('openapi/v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $document['servers'] = [['url' => url('/api/v1')]];

        return response()->json($document);
    }
}

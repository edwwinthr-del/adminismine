<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Client::query();

        $query->search($request->input('search'));

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $clients = $query->orderBy('name')->limit(200)->get();

        return response()->json(['data' => ClientResource::collection($clients)]);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = Client::create($request->validated());

        return response()->json(['data' => new ClientResource($client)], 201);
    }
}

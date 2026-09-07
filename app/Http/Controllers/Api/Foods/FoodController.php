<?php

namespace App\Http\Controllers\Api\Foods;

use App\Http\Controllers\Controller;
use App\Http\Requests\Foods\SearchFoodsRequest;
use App\Http\Resources\FoodResource;
use App\Models\Food;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** FR-25: search the approved food database in Arabic and English. */
class FoodController extends Controller
{
    public function search(SearchFoodsRequest $request): AnonymousResourceCollection
    {
        $foods = Food::query()
            ->approved()
            ->search(trim($request->string('q')))
            ->orderBy('name_en')
            ->paginate($request->integer('per_page', 20));

        return FoodResource::collection($foods);
    }
}

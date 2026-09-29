<?php

namespace App\Http\Controllers\Api\Nutritionists;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientNutritionistResource;
use Illuminate\Support\Facades\Auth;

/**
 * The patient's view of their own nutritionist: name, gender and the ways to
 * reach them. Resolved from the token — a patient has exactly one
 * nutritionist (BR-1) and there is no id in the URL to look at anyone
 * else's. Gated on the `client` role, not a permission: the PRD matrix has
 * no entry for it (same reasoning as `me/fcm-token`).
 */
class MyNutritionistController extends Controller
{
    public function show(): PatientNutritionistResource
    {
        $nutritionist = Auth::user()->nutritionist;

        abort_if($nutritionist === null, 404, 'No nutritionist is assigned to this account.');

        return new PatientNutritionistResource($nutritionist->load('nutritionistProfile'));
    }
}

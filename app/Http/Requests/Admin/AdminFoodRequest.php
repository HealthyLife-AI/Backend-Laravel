<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Foods\SubmitFoodRequest;

/**
 * Admin create/edit of a catalog food: same fields and bounds as a
 * nutritionist submission. The route permission (`foods.manage`) is the
 * gate; the controller decides source/status (admin-created foods go
 * straight to approved).
 */
class AdminFoodRequest extends SubmitFoodRequest {}

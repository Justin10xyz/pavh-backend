<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\UnitTypeResource;
use App\Models\UnitType;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnitTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UnitTypeResource::collection(UnitType::all());
    }
}

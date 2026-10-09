<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\UnitTypeResource;
use App\Models\UnitType;

class UnitTypeController extends Controller
{
    public function index()
    {
        return UnitTypeResource::collection(UnitType::all());
    }
}

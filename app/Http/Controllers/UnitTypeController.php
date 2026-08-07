<?php

namespace App\Http\Controllers;

use App\Http\Resources\UnitTypeResource;
use App\Models\UnitType;

class UnitTypeController extends Controller
{
    public function index()
    {
        return UnitTypeResource::collection(UnitType::all());
    }
}

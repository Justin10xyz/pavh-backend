<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\CommissionCategoryResource;
use App\Models\CommissionCategory;

class CommissionCategoryController extends Controller
{
    public function index()
    {
        return CommissionCategoryResource::collection(CommissionCategory::all());
    }
}

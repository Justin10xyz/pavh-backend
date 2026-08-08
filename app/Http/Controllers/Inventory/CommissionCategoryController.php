<?php

namespace App\Http\Controllers;

use App\Http\Resources\CommissionCategoryResource;
use App\Models\CommissionCategory;

class CommissionCategoryController extends Controller
{
    public function index()
    {
        return CommissionCategoryResource::collection(CommissionCategory::all());
    }
}

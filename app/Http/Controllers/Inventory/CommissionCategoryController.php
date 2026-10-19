<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Inventory\CommissionCategoryResource;
use App\Models\CommissionCategory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CommissionCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CommissionCategoryResource::collection(CommissionCategory::ordered()->get());
    }
}

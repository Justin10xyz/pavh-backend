<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code_prefix', 'product_form_type'])]
class Category extends Model
{
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function simpleProducts(): HasMany
    {
        return $this->hasMany(SimpleProduct::class);
    }

    public function usesSimpleProductForm(): bool
    {
        return $this->product_form_type === 'simple';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'stock',
        'images',
        'is_active',
    ];

    protected $hidden = ['images'];

    protected $appends = ['image_urls'];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'price' => 'integer',
            'stock' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function getImageUrlsAttribute(): array
    {
        return array_map(
            fn (string $path) => Storage::disk('public')->url($path),
            $this->images ?? []
        );
    }
}
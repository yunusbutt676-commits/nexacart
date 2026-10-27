<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StoreSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['Audio', 'Wireless Headphones', 8999, 20],
            ['Audio', 'Portable Bluetooth Speaker', 5499, 15],
            ['Accessories', 'Everyday Smartwatch', 12999, 12],
            ['Accessories', 'Travel Backpack', 4499, 25],
            ['Workspace', 'Mechanical Keyboard', 7499, 18],
            ['Workspace', 'Adjustable Laptop Stand', 2999, 30],
        ];

        foreach ($items as [$categoryName, $name, $price, $stock]) {
            $category = Category::firstOrCreate(
                ['slug' => Str::slug($categoryName)],
                ['name' => $categoryName]
            );

            Product::firstOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'category_id' => $category->id,
                    'name' => $name,
                    'description' => 'Demo catalogue product. Replace this description and upload your own product photos from the admin panel.',
                    'price' => $price * 100,
                    'stock' => $stock,
                    'images' => [],
                    'is_active' => true,
                ]
            );
        }
    }
}
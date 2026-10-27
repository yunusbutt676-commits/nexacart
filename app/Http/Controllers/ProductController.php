<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        return $this->listing($request);
    }

    public function adminIndex(Request $request)
    {
        return $this->listing($request, true);
    }

    private function listing(Request $request, bool $admin = false)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'sort' => [
                'nullable',
                Rule::in(['latest', 'price_asc', 'price_desc', 'name']),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Product::with('category')
            ->when(! $admin, fn ($q) => $q->where('is_active', true));

        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.$data['q'].'%');
        }

        if (! empty($data['category'])) {
            $query->where('category_id', $data['category']);
        }

        if (isset($data['min_price'])) {
            $query->where('price', '>=', $data['min_price']);
        }

        if (isset($data['max_price'])) {
            $query->where('price', '<=', $data['max_price']);
        }

        [$column, $direction] = match ($data['sort'] ?? 'latest') {
            'price_asc' => ['price', 'asc'],
            'price_desc' => ['price', 'desc'],
            'name' => ['name', 'asc'],
            default => ['created_at', 'desc'],
        };

        return $query
            ->orderBy($column, $direction)
            ->orderBy('id', 'desc')
            ->paginate(12)
            ->withQueryString();
    }

    public function show(string $slug)
    {
        return Product::with('category')
            ->where('is_active', true)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function store(Request $request)
    {
        return response()->json($this->save($request, new Product()), 201);
    }

    public function update(Request $request, Product $product)
    {
        return $this->save($request, $product);
    }

    private function save(Request $request, Product $product): Product
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:10000'],
            'price' => ['required', 'integer', 'min:100', 'max:99999999'],
            'stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['required', 'boolean'],
            'images' => ['sometimes', 'array', 'max:5'],
            'images.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:3072',
                'dimensions:max_width=6000,max_height=6000',
            ],
        ]);

        $oldImages = $product->images ?? [];
        $newImages = [];

        try {
            foreach ($request->file('images', []) as $image) {
                $path = $image->store('products', 'public');

                if (! $path) {
                    throw new \RuntimeException('Unable to save product image.');
                }

                $newImages[] = $path;
            }

            $product->fill(Arr::except($data, ['images']));

            if (! $product->exists) {
                $product->slug = Str::slug($data['name'])
                    .'-'.Str::lower(Str::random(8));
            }

            if ($newImages) {
                $product->images = $newImages;
            }

            $product->save();
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($newImages);
            throw $exception;
        }

        if ($newImages) {
            Storage::disk('public')->delete($oldImages);
        }

        return $product->load('category');
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->noContent();
    }
}
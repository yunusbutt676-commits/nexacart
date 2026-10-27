<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index()
    {
        return Category::orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name'],
        ]);

        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(6));

        return response()->json(Category::create($data), 201);
    }

    public function update(Request $request, Category $category)
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'name')->ignore($category->id),
            ],
        ]);

        $category->update($data);

        return $category;
    }

    public function destroy(Category $category)
    {
        abort_if(
            $category->products()->withTrashed()->exists(),
            422,
            'This category has products. Move its products before deleting it.'
        );

        $category->delete();

        return response()->noContent();
    }
}
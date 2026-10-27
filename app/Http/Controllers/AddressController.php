<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AddressController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'address' => Address::where(
                'user_id',
                $request->user()->id
            )->first(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => [
                'required',
                'string',
                'max:30',
                'regex:/^[0-9+() .-]{7,30}$/',
            ],
            'line1' => ['required', 'string', 'max:200'],
            'line2' => ['nullable', 'string', 'max:200'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country_code' => [
                'required',
                Rule::in(['PK', 'US', 'GB', 'AE', 'SA', 'CA', 'AU']),
            ],
        ]);

        $address = DB::transaction(function () use ($request, $data) {
            $userId = $request->user()->id;

            User::whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            $address = Address::where('user_id', $userId)->first()
                ?? new Address();

            $address->user_id = $userId;
            $address->fill($data);
            $address->save();

            return $address;
        });

        return response()->json(['address' => $address]);
    }
}
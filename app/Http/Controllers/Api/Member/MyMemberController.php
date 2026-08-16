<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyMemberController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $member = Member::query()->where('user_id',$request->user()->id)->with('profile')->first();
        return response()->json(['data' => $member]);
    }
}

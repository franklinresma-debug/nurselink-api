<?php

namespace App\Http\Controllers\Api\Admin\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberDirectoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search',''));
        $status = trim((string) $request->query('status',''));
        $members = Member::query()->with('user:id,name,email','profile')
            ->when($status !== '', fn($q) => $q->where('status',$status))
            ->when($search !== '', fn($q) => $q->where(fn($x) => $x->where('member_no','like',"%{$search}%")->orWhereHas('user', fn($u) => $u->where('name','like',"%{$search}%")->orWhere('email','like',"%{$search}%"))))
            ->latest('joined_at')->paginate(25);
        return response()->json($members);
    }

    public function show(Member $member): JsonResponse
    {
        return response()->json(['data' => $member->load('user:id,name,email,status','profile','sourceApplication.events')]);
    }
}

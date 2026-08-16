<?php
namespace App\Http\Controllers\Api\Member\Privacy;
use App\Http\Controllers\Controller; use App\Models\PrivacyRequest; use App\Services\Privacy\PrivacyRequestService; use Illuminate\Http\Request;
class PrivacyCenterController extends Controller { public function index(Request $r){return response()->json(['request_types'=>config('privacy.request_types'),'requests'=>PrivacyRequest::query()->where('user_id',$r->user()->id)->latest()->get()]);} public function store(Request $r,PrivacyRequestService $s){$d=$r->validate(['request_type'=>'required|string','details'=>'nullable|string|max:5000']);return response()->json($s->submit($r->user(),$d['request_type'],$d['details']??null),201);} }

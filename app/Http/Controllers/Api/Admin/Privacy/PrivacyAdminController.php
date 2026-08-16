<?php
namespace App\Http\Controllers\Api\Admin\Privacy;
use App\Http\Controllers\Controller; use App\Models\PrivacyRequest; use App\Services\Privacy\PrivacyRequestService; use Illuminate\Http\Request;
class PrivacyAdminController extends Controller { public function index(Request $r){$q=PrivacyRequest::query()->latest();if($r->filled('status'))$q->where('status',$r->string('status'));return response()->json($q->paginate(50));} public function update(Request $r,PrivacyRequest $privacyRequest,PrivacyRequestService $s){$d=$r->validate(['status'=>'required|string','resolution_note'=>'nullable|string|max:5000']);return response()->json($s->transition($privacyRequest,$d['status'],$r->user(),$d['resolution_note']??null));} }

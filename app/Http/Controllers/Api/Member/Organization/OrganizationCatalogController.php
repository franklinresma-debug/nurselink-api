<?php
namespace App\Http\Controllers\Api\Member\Organization;
use App\Http\Controllers\Controller; use App\Models\Initiative; use App\Models\PolicyRecord; use Illuminate\Http\Request;
class OrganizationCatalogController extends Controller {
 public function initiatives(Request $r){$q=Initiative::query()->whereIn('visibility',['members','public'])->whereNotNull('published_at')->with(['milestones'=>fn($q)=>$q->orderBy('sort_order'),'partners','beneficiaries','updates'=>fn($q)=>$q->whereNotNull('published_at')->latest('published_at')]);if($r->filled('type'))$q->where('type',$r->string('type'));if($r->filled('status'))$q->where('status',$r->string('status'));return response()->json($q->latest('published_at')->paginate(20));}
 public function initiative(Initiative $initiative){abort_unless(in_array($initiative->visibility,['members','public'],true)&&$initiative->published_at,404);return response()->json($initiative->load(['milestones'=>fn($q)=>$q->orderBy('sort_order'),'partners','beneficiaries','updates'=>fn($q)=>$q->whereNotNull('published_at')->latest('published_at')]));}
 public function policies(Request $r){$q=PolicyRecord::query()->whereIn('visibility',['members','public'])->whereNotNull('published_at')->with(['stageEvents'=>fn($q)=>$q->latest('occurred_at'),'stakeholders']);if($r->filled('status'))$q->where('status',$r->string('status'));return response()->json($q->latest('published_at')->paginate(20));}
 public function policy(PolicyRecord $policy){abort_unless(in_array($policy->visibility,['members','public'],true)&&$policy->published_at,404);return response()->json($policy->load(['stageEvents'=>fn($q)=>$q->latest('occurred_at'),'stakeholders']));}
}

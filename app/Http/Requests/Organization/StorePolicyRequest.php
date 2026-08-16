<?php
namespace App\Http\Requests\Organization;
use Illuminate\Foundation\Http\FormRequest; use Illuminate\Validation\Rule;
class StorePolicyRequest extends FormRequest { public function authorize():bool{return $this->user()?->hasPermission('policy.manage')??false;} public function rules():array{return ['title'=>['required','string','max:255'],'issue_area'=>['nullable','string','max:255'],'objective'=>['nullable','string','max:10000'],'position_summary'=>['nullable','string','max:10000'],'lead_committee'=>['nullable','string','max:255'],'target_body'=>['nullable','string','max:255'],'owner_user_id'=>['nullable','uuid','exists:users,id'],'visibility'=>['nullable',Rule::in(['internal','members','public'])],'audience_filters'=>['nullable','array']];} }

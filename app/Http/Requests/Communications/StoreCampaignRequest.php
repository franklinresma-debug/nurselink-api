<?php
namespace App\Http\Requests\Communications;
use Illuminate\Foundation\Http\FormRequest; use Illuminate\Validation\Rule;
class StoreCampaignRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{return [
  'name'=>['required','string','max:180'],'category'=>['required',Rule::in(config('communications.broadcast_categories',[]))],
  'subject'=>['required','string','max:255'],'body'=>['required','string','max:30000'],
  'channels'=>['required','array','min:1'],'channels.*'=>[Rule::in(['in_app','email','sms','push','whatsapp'])],
  'audience_filters'=>['required','array','min:1'],'priority'=>['nullable',Rule::in(['normal','high','critical'])],
  'scheduled_at'=>['nullable','date'],'template_id'=>['nullable','uuid','exists:message_templates,id']
 ];}
}

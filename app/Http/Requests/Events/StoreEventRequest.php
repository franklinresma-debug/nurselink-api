<?php
namespace App\Http\Requests\Events;
use Illuminate\Foundation\Http\FormRequest; use Illuminate\Validation\Rule;
class StoreEventRequest extends FormRequest {
 public function authorize():bool{return true;}
 public function rules():array{$need=$this->isMethod('post')?'required':'sometimes';return [
  'title'=>[$need,'string','max:220'],'description'=>['nullable','string','max:30000'],
  'event_type'=>[$need,Rule::in(['seminar','webinar','workshop','conference','meeting','other'])],
  'format'=>[$need,Rule::in(['online','onsite','hybrid'])],
  'starts_at'=>[$need,'date'],'ends_at'=>[$need,'date','after:starts_at'],'timezone'=>['sometimes','string','max:64'],
  'venue_name'=>['nullable','string','max:220'],'venue_address'=>['nullable','string','max:2000'],'online_url'=>['nullable','url','max:2000'],
  'capacity'=>['nullable','integer','min:1','max:100000'],'waitlist_enabled'=>['sometimes','boolean'],
  'registration_opens_at'=>['nullable','date'],'registration_closes_at'=>['nullable','date','before_or_equal:starts_at'],
  'certificate_enabled'=>['sometimes','boolean'],'audience_filters'=>['nullable','array'],'status'=>['sometimes',Rule::in(['draft','published'])]
 ];}
}

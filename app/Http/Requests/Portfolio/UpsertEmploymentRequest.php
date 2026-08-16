<?php
namespace App\Http\Requests\Portfolio;
use Illuminate\Foundation\Http\FormRequest;
class UpsertEmploymentRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['position_title'=>['required','string','max:180'],'employer'=>['required','string','max:220'],'facility_type'=>['nullable','string','max:120'],'country'=>['nullable','string','size:2'],'city'=>['nullable','string','max:120'],'started_on'=>['nullable','date'],'ended_on'=>['nullable','date','after_or_equal:started_on'],'is_current'=>['boolean'],'responsibilities'=>['nullable','string','max:5000']];} }

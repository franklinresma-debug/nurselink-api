<?php
namespace App\Http\Requests\Portfolio;
use Illuminate\Foundation\Http\FormRequest;
class UpsertEducationRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['qualification'=>['required','string','max:180'],'field_of_study'=>['nullable','string','max:180'],'institution'=>['required','string','max:220'],'country'=>['nullable','string','size:2'],'started_on'=>['nullable','date'],'completed_on'=>['nullable','date','after_or_equal:started_on']];} }

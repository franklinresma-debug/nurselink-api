<?php
namespace App\Http\Requests\Qualifications;
use Illuminate\Foundation\Http\FormRequest;
class StartQualificationAssessmentRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['framework_id'=>['required','uuid','exists:qualification_frameworks,id'],'target_level_id'=>['nullable','uuid','exists:qualification_framework_levels,id']];} }

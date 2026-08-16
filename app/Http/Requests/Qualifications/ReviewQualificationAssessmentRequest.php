<?php
namespace App\Http\Requests\Qualifications;
use Illuminate\Foundation\Http\FormRequest;
class ReviewQualificationAssessmentRequest extends FormRequest { public function authorize():bool{return true;} public function rules():array{return ['decision'=>['required','in:assessed,returned'],'assessor_note'=>['nullable','string','max:4000']];} }

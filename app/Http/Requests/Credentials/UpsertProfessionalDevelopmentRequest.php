<?php
namespace App\Http\Requests\Credentials;
use Illuminate\Foundation\Http\FormRequest;
class UpsertProfessionalDevelopmentRequest extends FormRequest
{
    public function authorize():bool{return true;}
    public function rules():array{return [
        'record_type'=>['required','in:training,seminar,cpd,workshop,conference'],
        'title'=>['required','string','max:180'],
        'provider'=>['nullable','string','max:180'],
        'country'=>['nullable','string','size:2'],
        'completed_on'=>['nullable','date'],
        'cpd_units'=>['nullable','numeric','min:0','max:9999.99'],
        'hours'=>['nullable','numeric','min:0','max:9999.99'],
        'evidence_document_id'=>['nullable','uuid'],
    ];}
}

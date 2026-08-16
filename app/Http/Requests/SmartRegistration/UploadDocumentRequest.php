<?php
namespace App\Http\Requests\SmartRegistration;
use Illuminate\Foundation\Http\FormRequest;
class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return ['category'=>['required','string','max:60','in:cv,prc_license,diploma,employment_certificate,training_certificate,international_license,passport_id,other'],
                'file'=>['required','file','max:15360','mimes:pdf,jpg,jpeg,png,docx']];
    }
}

<?php
namespace App\Http\Requests\SmartRegistration;
use Illuminate\Foundation\Http\FormRequest;
class ConfirmFactRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['value'=>['nullable','string','max:4000']]; }
}

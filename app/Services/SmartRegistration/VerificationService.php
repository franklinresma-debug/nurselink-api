<?php
namespace App\Services\SmartRegistration;
use App\Models\ExtractedFact;
use App\Models\User;
use Illuminate\Validation\ValidationException;
class VerificationService
{
    public function verify(ExtractedFact $fact, User $officer, string $status, ?string $note=null): ExtractedFact
    {
        if (! in_array($status,['verified','unable_to_verify','discrepancy'],true)) throw ValidationException::withMessages(['status'=>'Invalid verification status.']);
        $fact->update(['verification_status'=>$status,'verified_by_user_id'=>$officer->id,'verification_note'=>$note,'verified_at'=>now()]);
        return $fact->fresh();
    }
}

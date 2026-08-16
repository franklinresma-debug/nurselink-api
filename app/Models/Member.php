<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected function casts(): array { return ['joined_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function sourceApplication(): BelongsTo { return $this->belongsTo(Application::class, 'approved_from_application_id'); }
    public function profile(): HasOne { return $this->hasOne(MemberProfile::class); }
    public function portfolioSummary(): HasOne { return $this->hasOne(PortfolioSummary::class); }
    public function education(): HasMany { return $this->hasMany(PortfolioEducation::class); }
    public function employment(): HasMany { return $this->hasMany(PortfolioEmployment::class); }
    public function specialties(): HasMany { return $this->hasMany(PortfolioSpecialty::class); }
    public function competencies(): HasMany { return $this->hasMany(PortfolioCompetency::class); }
    public function technologySkills(): HasMany { return $this->hasMany(PortfolioTechnologySkill::class); }
    public function languages(): HasMany { return $this->hasMany(PortfolioLanguage::class); }
    public function timelineEvents(): HasMany { return $this->hasMany(PortfolioTimelineEvent::class); }
    public function documents(): HasMany { return $this->hasMany(MemberDocument::class); }
    public function credentials(): HasMany { return $this->hasMany(ProfessionalCredential::class); }
    public function professionalDevelopment(): HasMany { return $this->hasMany(ProfessionalDevelopmentRecord::class); }
    public function qualificationAssessments(): HasMany { return $this->hasMany(QualificationAssessment::class); }
    public function eventRegistrations(): HasMany { return $this->hasMany(EventRegistration::class); }
}

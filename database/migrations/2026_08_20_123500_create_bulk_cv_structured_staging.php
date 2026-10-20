<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'nurselink_bulk_intake_education',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger(
                    'candidate_id'
                );

                $table->unsignedBigInteger(
                    'source_file_id'
                )->nullable();

                $table->string(
                    'qualification',
                    180
                );

                $table->string(
                    'field_of_study',
                    180
                )->nullable();

                $table->string(
                    'institution',
                    220
                )->nullable();

                $table->string(
                    'country',
                    120
                )->nullable();

                $table->date(
                    'started_on'
                )->nullable();

                $table->date(
                    'completed_on'
                )->nullable();

                $table->decimal(
                    'confidence',
                    5,
                    4
                )->nullable();

                $table->string(
                    'review_status',
                    40
                )->default('review_required');

                $table->text(
                    'review_notes'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'candidate_id',
                    'bi_edu_candidate_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_candidates'
                    )
                    ->cascadeOnDelete();

                $table->foreign(
                    'source_file_id',
                    'bi_edu_file_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_files'
                    )
                    ->nullOnDelete();
            }
        );


        Schema::create(
            'nurselink_bulk_intake_employment',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger(
                    'candidate_id'
                );

                $table->unsignedBigInteger(
                    'source_file_id'
                )->nullable();

                $table->string(
                    'position_title',
                    190
                );

                $table->string(
                    'employer',
                    255
                )->nullable();

                $table->string(
                    'facility_type',
                    120
                )->nullable();

                $table->string(
                    'country',
                    120
                )->nullable();

                $table->string(
                    'city',
                    160
                )->nullable();

                $table->date(
                    'started_on'
                )->nullable();

                $table->date(
                    'ended_on'
                )->nullable();

                $table->boolean(
                    'is_current'
                )->default(false);

                $table->text(
                    'responsibilities'
                )->nullable();

                $table->decimal(
                    'confidence',
                    5,
                    4
                )->nullable();

                $table->string(
                    'review_status',
                    40
                )->default('review_required');

                $table->text(
                    'review_notes'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'candidate_id',
                    'bi_emp_candidate_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_candidates'
                    )
                    ->cascadeOnDelete();

                $table->foreign(
                    'source_file_id',
                    'bi_emp_file_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_files'
                    )
                    ->nullOnDelete();
            }
        );


        Schema::create(
            'nurselink_bulk_intake_competencies',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger(
                    'candidate_id'
                );

                $table->unsignedBigInteger(
                    'source_file_id'
                )->nullable();

                $table->string(
                    'domain',
                    120
                )->nullable();

                $table->string(
                    'name',
                    220
                );

                $table->string(
                    'proficiency',
                    40
                )->nullable();

                $table->decimal(
                    'confidence',
                    5,
                    4
                )->nullable();

                $table->string(
                    'review_status',
                    40
                )->default('review_required');

                $table->text(
                    'review_notes'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'candidate_id',
                    'bi_comp_candidate_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_candidates'
                    )
                    ->cascadeOnDelete();

                $table->foreign(
                    'source_file_id',
                    'bi_comp_file_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_files'
                    )
                    ->nullOnDelete();
            }
        );


        Schema::create(
            'nurselink_bulk_intake_languages',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger(
                    'candidate_id'
                );

                $table->unsignedBigInteger(
                    'source_file_id'
                )->nullable();

                $table->string(
                    'language',
                    100
                );

                $table->string(
                    'speaking',
                    40
                )->nullable();

                $table->string(
                    'reading',
                    40
                )->nullable();

                $table->string(
                    'writing',
                    40
                )->nullable();

                $table->decimal(
                    'confidence',
                    5,
                    4
                )->nullable();

                $table->string(
                    'review_status',
                    40
                )->default('review_required');

                $table->timestamps();

                $table->foreign(
                    'candidate_id',
                    'bi_lang_candidate_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_candidates'
                    )
                    ->cascadeOnDelete();

                $table->foreign(
                    'source_file_id',
                    'bi_lang_file_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_files'
                    )
                    ->nullOnDelete();
            }
        );


        Schema::create(
            'nurselink_bulk_intake_references',
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger(
                    'candidate_id'
                );

                $table->unsignedBigInteger(
                    'source_file_id'
                )->nullable();

                $table->string(
                    'name',
                    180
                );

                $table->string(
                    'position_title',
                    220
                )->nullable();

                $table->string(
                    'organization',
                    255
                )->nullable();

                $table->string(
                    'phone',
                    190
                )->nullable();

                $table->string(
                    'email',
                    255
                )->nullable();

                $table->string(
                    'reference_type',
                    80
                )->default(
                    'professional_reference'
                );

                $table->string(
                    'privacy_status',
                    40
                )->default('private');

                $table->decimal(
                    'confidence',
                    5,
                    4
                )->nullable();

                $table->string(
                    'review_status',
                    40
                )->default('review_required');

                $table->text(
                    'review_notes'
                )->nullable();

                $table->timestamps();

                $table->foreign(
                    'candidate_id',
                    'bi_ref_candidate_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_candidates'
                    )
                    ->cascadeOnDelete();

                $table->foreign(
                    'source_file_id',
                    'bi_ref_file_fk'
                )
                    ->references('id')
                    ->on(
                        'nurselink_bulk_intake_files'
                    )
                    ->nullOnDelete();
            }
        );
    }


    public function down(): void
    {
        Schema::dropIfExists(
            'nurselink_bulk_intake_references'
        );

        Schema::dropIfExists(
            'nurselink_bulk_intake_languages'
        );

        Schema::dropIfExists(
            'nurselink_bulk_intake_competencies'
        );

        Schema::dropIfExists(
            'nurselink_bulk_intake_employment'
        );

        Schema::dropIfExists(
            'nurselink_bulk_intake_education'
        );
    }
};

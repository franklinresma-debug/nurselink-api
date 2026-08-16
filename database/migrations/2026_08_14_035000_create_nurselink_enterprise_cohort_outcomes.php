<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_enterprise_cohort_outcomes')) {
            Schema::create(
                'nurselink_enterprise_cohort_outcomes',
                function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('cohort_id');
                    $this->userIdDefinition($table);
                    $table->string('outcome_status', 40)
                        ->default('in_progress');
                    $table->string('completion_basis', 40)
                        ->default('admin_review');
                    $table->text('member_summary')->nullable();
                    $table->text('internal_note')->nullable();
                    $table->boolean('member_visible')->default(true);
                    $table->timestamp('completed_at')->nullable();
                    $table->string('reviewed_by', 191)->nullable();
                    $table->timestamp('reviewed_at')->nullable();
                    $table->timestamps();

                    $table->unique(
                        ['cohort_id', 'user_id'],
                        'nl_enterprise_outcome_user_unique'
                    );

                    $table->index(
                        ['cohort_id', 'outcome_status'],
                        'nl_enterprise_outcome_cohort_status'
                    );

                    $table->index(
                        ['user_id', 'outcome_status'],
                        'nl_enterprise_outcome_user_status'
                    );

                    $table->index(
                        ['member_visible', 'reviewed_at'],
                        'nl_enterprise_outcome_visibility'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'nurselink_enterprise_cohort_outcomes'
        );
    }

    private function userIdDefinition(
        Blueprint $table
    ): void {
        $column = DB::selectOne(
            "SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'id'
             LIMIT 1"
        );

        if (! $column) {
            throw new RuntimeException(
                'Unable to inspect users.id.'
            );
        }

        $dataType = strtolower(
            (string) $column->DATA_TYPE
        );
        $columnType = strtolower(
            (string) $column->COLUMN_TYPE
        );
        $unsigned = str_contains(
            $columnType,
            'unsigned'
        );

        switch ($dataType) {
            case 'tinyint':
                $definition =
                    $table->tinyInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'smallint':
                $definition =
                    $table->smallInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'mediumint':
                $definition =
                    $table->mediumInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'int':
            case 'integer':
                $definition =
                    $table->integer('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'bigint':
                $definition =
                    $table->bigInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'char':
                $length = (int) (
                    $column->CHARACTER_MAXIMUM_LENGTH ?: 36
                );
                $table->char(
                    'user_id',
                    max(1, min($length, 255))
                );
                break;
            case 'varchar':
                $length = (int) (
                    $column->CHARACTER_MAXIMUM_LENGTH ?: 191
                );
                $table->string(
                    'user_id',
                    max(1, min($length, 512))
                );
                break;
            default:
                throw new RuntimeException(
                    'Unsupported users.id data type for NurseLink: '
                    . $dataType
                );
        }

        $table->index('user_id');
    }
};

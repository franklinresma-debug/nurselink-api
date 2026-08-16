<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_enterprise_cohort_goals')) {
            Schema::create(
                'nurselink_enterprise_cohort_goals',
                function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('cohort_id');
                    $table->string('title', 190);
                    $table->text('description')->nullable();
                    $table->string('goal_type', 40)->default('participation');
                    $table->decimal('target_value', 12, 2)->nullable();
                    $table->string('target_unit', 80)->nullable();
                    $table->string('status', 30)->default('active');
                    $table->string('visibility', 40)
                        ->default('members_and_partners');
                    $table->timestamp('due_at')->nullable();
                    $table->string('created_by', 191)->nullable();
                    $table->string('updated_by', 191)->nullable();
                    $table->timestamps();

                    $table->index(
                        ['cohort_id', 'status'],
                        'nl_enterprise_goal_cohort_status'
                    );
                    $table->index(
                        ['visibility', 'due_at'],
                        'nl_enterprise_goal_visibility_due'
                    );
                }
            );
        }

        if (! Schema::hasTable('nurselink_enterprise_cohort_progress')) {
            Schema::create(
                'nurselink_enterprise_cohort_progress',
                function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('goal_id');
                    $this->userIdDefinition($table);
                    $table->string('status', 30)->default('not_started');
                    $table->decimal('progress_value', 12, 2)->nullable();
                    $table->text('member_note')->nullable();
                    $table->timestamp('completed_at')->nullable();
                    $table->timestamps();

                    $table->unique(
                        ['goal_id', 'user_id'],
                        'nl_enterprise_goal_user_unique'
                    );
                    $table->index(
                        ['goal_id', 'status'],
                        'nl_enterprise_goal_progress_status'
                    );
                    $table->index(
                        ['user_id', 'status'],
                        'nl_enterprise_progress_user_status'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'nurselink_enterprise_cohort_progress'
        );
        Schema::dropIfExists(
            'nurselink_enterprise_cohort_goals'
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
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;
            case 'smallint':
                $definition =
                    $table->smallInteger('user_id');
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;
            case 'mediumint':
                $definition =
                    $table->mediumInteger('user_id');
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;
            case 'int':
            case 'integer':
                $definition =
                    $table->integer('user_id');
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;
            case 'bigint':
                $definition =
                    $table->bigInteger('user_id');
                if ($unsigned) {
                    $definition->unsigned();
                }
                break;
            case 'char':
                $length = (int) (
                    $column->CHARACTER_MAXIMUM_LENGTH
                    ?: 36
                );
                $table->char(
                    'user_id',
                    max(1, min($length, 255))
                );
                break;
            case 'varchar':
                $length = (int) (
                    $column->CHARACTER_MAXIMUM_LENGTH
                    ?: 191
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

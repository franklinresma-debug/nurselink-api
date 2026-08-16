<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_enterprise_cohorts')) {
            Schema::create(
                'nurselink_enterprise_cohorts',
                function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('partner_organization_id');
                    $table->string('name', 190);
                    $table->string('code', 80)->unique();
                    $table->text('description')->nullable();
                    $table->string('status', 30)->default('planned');
                    $table->timestamp('starts_at')->nullable();
                    $table->timestamp('ends_at')->nullable();
                    $table->string('created_by', 191)->nullable();
                    $table->string('updated_by', 191)->nullable();
                    $table->timestamps();

                    $table->index(
                        ['partner_organization_id', 'status'],
                        'nl_enterprise_cohort_org_status'
                    );
                    $table->index(
                        ['starts_at', 'ends_at'],
                        'nl_enterprise_cohort_dates'
                    );
                }
            );
        }

        if (! Schema::hasTable('nurselink_enterprise_cohort_members')) {
            Schema::create(
                'nurselink_enterprise_cohort_members',
                function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('cohort_id');
                    $this->userIdDefinition($table);
                    $table->string('status', 30)->default('active');
                    $table->timestamp('joined_at')->nullable();
                    $table->timestamp('completed_at')->nullable();
                    $table->timestamp('inactive_at')->nullable();
                    $table->text('internal_note')->nullable();
                    $table->timestamps();

                    $table->unique(
                        ['cohort_id', 'user_id'],
                        'nl_enterprise_cohort_user_unique'
                    );
                    $table->index(
                        ['cohort_id', 'status'],
                        'nl_enterprise_cohort_member_status'
                    );
                    $table->index(
                        ['user_id', 'status'],
                        'nl_enterprise_user_status'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_enterprise_cohort_members');
        Schema::dropIfExists('nurselink_enterprise_cohorts');
    }

    private function userIdDefinition(Blueprint $table): void
    {
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

        $dataType = strtolower((string) $column->DATA_TYPE);
        $columnType = strtolower((string) $column->COLUMN_TYPE);
        $unsigned = str_contains($columnType, 'unsigned');

        switch ($dataType) {
            case 'tinyint':
                $definition = $table->tinyInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'smallint':
                $definition = $table->smallInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'mediumint':
                $definition = $table->mediumInteger('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'int':
            case 'integer':
                $definition = $table->integer('user_id');
                if ($unsigned) $definition->unsigned();
                break;
            case 'bigint':
                $definition = $table->bigInteger('user_id');
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_enterprise_support_checkins')) {
            Schema::create(
                'nurselink_enterprise_support_checkins',
                function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('cohort_id');
                    $this->userIdDefinition($table);
                    $table->string('checkin_type', 40)
                        ->default('general');
                    $table->string('support_level', 30)
                        ->default('none');
                    $table->string('status', 30)
                        ->default('open');
                    $table->string('member_sentiment', 30)
                        ->nullable();
                    $table->text('member_note')->nullable();
                    $table->text('admin_note')->nullable();
                    $table->string('assigned_to', 191)
                        ->nullable();
                    $table->timestamp('submitted_at');
                    $table->timestamp('acknowledged_at')
                        ->nullable();
                    $table->timestamp('resolved_at')
                        ->nullable();
                    $table->timestamps();

                    $table->index(
                        ['cohort_id', 'status'],
                        'nl_enterprise_support_cohort_status'
                    );

                    $table->index(
                        ['user_id', 'status'],
                        'nl_enterprise_support_user_status'
                    );

                    $table->index(
                        ['support_level', 'submitted_at'],
                        'nl_enterprise_support_level_date'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'nurselink_enterprise_support_checkins'
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

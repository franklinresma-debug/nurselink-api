<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_mentoring_profiles')) {
            Schema::create(
                'nurselink_mentoring_profiles',
                function (Blueprint $table): void {
                    $table->id();
                    $this->userIdDefinition($table, 'user_id');
                    $table->string('role_preference', 30)
                        ->default('mentee');
                    $table->string('availability', 30)
                        ->default('open');
                    $table->string('focus_areas', 1000)
                        ->nullable();
                    $table->string('languages', 500)
                        ->nullable();
                    $table->string('timezone', 120)
                        ->nullable();
                    $table->text('bio')->nullable();
                    $table->boolean('discoverable')
                        ->default(false);
                    $table->timestamps();

                    $table->unique(
                        'user_id',
                        'nl_mentoring_profile_user_unique'
                    );
                    $table->index(
                        ['discoverable', 'availability']
                    );
                    $table->index('role_preference');
                }
            );
        }

        if (! Schema::hasTable('nurselink_mentoring_requests')) {
            Schema::create(
                'nurselink_mentoring_requests',
                function (Blueprint $table): void {
                    $table->id();
                    $this->userIdDefinition(
                        $table,
                        'mentor_user_id'
                    );
                    $this->userIdDefinition(
                        $table,
                        'mentee_user_id'
                    );
                    $table->string('status', 30)
                        ->default('requested');
                    $table->string('focus_area', 190)
                        ->nullable();
                    $table->text('message')->nullable();
                    $table->timestamp('requested_at')
                        ->nullable();
                    $table->timestamp('accepted_at')
                        ->nullable();
                    $table->timestamp('declined_at')
                        ->nullable();
                    $table->timestamp('cancelled_at')
                        ->nullable();
                    $table->timestamp('completed_at')
                        ->nullable();
                    $table->timestamps();

                    $table->index(
                        ['mentor_user_id', 'status'],
                        'nl_mentor_status_idx'
                    );
                    $table->index(
                        ['mentee_user_id', 'status'],
                        'nl_mentee_status_idx'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'nurselink_mentoring_requests'
        );
        Schema::dropIfExists(
            'nurselink_mentoring_profiles'
        );
    }

    private function userIdDefinition(
        Blueprint $table,
        string $name
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
                    $table->tinyInteger($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'smallint':
                $definition =
                    $table->smallInteger($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'mediumint':
                $definition =
                    $table->mediumInteger($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'int':
            case 'integer':
                $definition =
                    $table->integer($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'bigint':
                $definition =
                    $table->bigInteger($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'char':
                $length = (int) (
                    $column->CHARACTER_MAXIMUM_LENGTH
                    ?: 36
                );
                $table->char(
                    $name,
                    max(1, min($length, 255))
                );
                break;
            case 'varchar':
                $length = (int) (
                    $column->CHARACTER_MAXIMUM_LENGTH
                    ?: 191
                );
                $table->string(
                    $name,
                    max(1, min($length, 512))
                );
                break;
            default:
                throw new RuntimeException(
                    'Unsupported users.id data type for NurseLink: '
                    . $dataType
                );
        }

        $table->index($name);
    }
};

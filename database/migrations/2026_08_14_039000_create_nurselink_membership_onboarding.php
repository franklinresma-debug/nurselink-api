<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_membership_onboarding')) {
            Schema::create(
                'nurselink_membership_onboarding',
                function (Blueprint $table): void {
                    $table->id();
                    $table->unsignedBigInteger('membership_id')->unique();
                    $this->userIdDefinition($table);
                    $table->string('status', 30)->default('pending')->index();
                    $table->string('assigned_admin_user_id', 191)->nullable()->index();
                    $table->timestamp('due_at')->nullable()->index();
                    $table->timestamp('welcome_viewed_at')->nullable();
                    $table->timestamp('orientation_started_at')->nullable();
                    $table->timestamp('orientation_completed_at')->nullable();
                    $table->timestamp('last_member_activity_at')->nullable()->index();
                    $table->timestamp('last_admin_action_at')->nullable()->index();
                    $table->timestamp('completed_at')->nullable()->index();
                    $table->text('admin_note')->nullable();
                    $table->timestamps();

                    $table->index(
                        ['status', 'due_at'],
                        'nl_membership_onboarding_status_due'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_membership_onboarding');
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
            throw new RuntimeException('Unable to inspect users.id.');
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
                $length = (int) ($column->CHARACTER_MAXIMUM_LENGTH ?: 36);
                $table->char('user_id', max(1, min($length, 255)));
                break;
            case 'varchar':
                $length = (int) ($column->CHARACTER_MAXIMUM_LENGTH ?: 191);
                $table->string('user_id', max(1, min($length, 512)));
                break;
            default:
                throw new RuntimeException(
                    'Unsupported users.id data type for NurseLink: '.$dataType
                );
        }

        $table->index('user_id');
    }
};

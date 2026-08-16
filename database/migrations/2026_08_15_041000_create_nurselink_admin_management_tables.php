<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_admin_profiles')) {
            Schema::create('nurselink_admin_profiles', function (Blueprint $table): void {
                $table->id();
                $this->userIdDefinition($table, 'user_id');
                $table->string('department_unit', 190)->nullable();
                $table->boolean('active')->default(false);
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique('user_id');
                $table->index(['active', 'department_unit']);
            });
        }

        if (! Schema::hasTable('nurselink_admin_role_assignments')) {
            Schema::create('nurselink_admin_role_assignments', function (Blueprint $table): void {
                $table->id();
                $this->userIdDefinition($table, 'user_id');
                $table->string('role_key', 80);
                $table->boolean('active')->default(true);
                $table->string('assigned_by_user_id', 191)->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'role_key'], 'nl_admin_role_user_role_unique');
                $table->index(['role_key', 'active']);
            });
        }

        if (! Schema::hasTable('nurselink_admin_invitations')) {
            Schema::create('nurselink_admin_invitations', function (Blueprint $table): void {
                $table->id();
                $table->string('email', 255);
                $table->string('department_unit', 190)->nullable();
                $table->text('roles_json');
                $table->string('token_hash', 64)->unique();
                $table->string('status', 40)->default('invitation_sent');
                $table->string('invited_by_user_id', 191)->nullable();
                $table->string('accepted_by_user_id', 191)->nullable();
                $table->string('delivery_status', 40)->default('pending');
                $table->text('delivery_error')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->index(['email', 'status']);
                $table->index(['status', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_admin_invitations');
        Schema::dropIfExists('nurselink_admin_role_assignments');
        Schema::dropIfExists('nurselink_admin_profiles');
    }

    private function userIdDefinition(Blueprint $table, string $name): void
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
                $definition = $table->tinyInteger($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'smallint':
                $definition = $table->smallInteger($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'mediumint':
                $definition = $table->mediumInteger($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'int':
            case 'integer':
                $definition = $table->integer($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'bigint':
                $definition = $table->bigInteger($name);
                if ($unsigned) $definition->unsigned();
                break;
            case 'char':
                $length = (int) ($column->CHARACTER_MAXIMUM_LENGTH ?: 36);
                $table->char($name, max(1, min($length, 255)));
                break;
            case 'varchar':
                $length = (int) ($column->CHARACTER_MAXIMUM_LENGTH ?: 191);
                $table->string($name, max(1, min($length, 512)));
                break;
            default:
                throw new RuntimeException('Unsupported users.id data type for NurseLink: ' . $dataType);
        }

        $table->index($name);
    }
};

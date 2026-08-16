<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_events')) {
            Schema::create('nurselink_events', function (Blueprint $table): void {
                $table->id();
                $table->string('title', 190);
                $table->string('event_type', 60)->default('webinar');
                $table->string('delivery_mode', 30)->default('online');
                $table->text('description')->nullable();
                $table->string('organizer', 190)->nullable();
                $table->string('venue', 255)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('country', 120)->nullable();
                $table->string('meeting_url', 512)->nullable();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->nullable();
                $table->unsignedInteger('capacity')->nullable();
                $table->string('status', 30)->default('draft');
                $table->boolean('member_only')->default(true);
                $table->boolean('registration_required')->default(true);
                $table->timestamp('registration_deadline')->nullable();
                $table->decimal('learning_hours', 6, 2)->nullable();
                $table->decimal('cpd_units_claimed', 6, 2)->nullable();
                $table->string('created_by', 191)->nullable();
                $table->string('updated_by', 191)->nullable();
                $table->timestamps();

                $table->index(['status', 'starts_at']);
                $table->index(['event_type', 'starts_at']);
                $table->index(['delivery_mode', 'starts_at']);
            });
        }

        if (! Schema::hasTable('nurselink_event_registrations')) {
            Schema::create('nurselink_event_registrations', function (Blueprint $table): void {
                $table->id();
                $this->userIdDefinition($table);
                $table->unsignedBigInteger('event_id');
                $table->string('status', 30)->default('registered');
                $table->timestamp('registered_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('attended_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['event_id', 'user_id']);
                $table->index(['event_id', 'status']);
                $table->index(['user_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_event_registrations');
        Schema::dropIfExists('nurselink_events');
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
                    'Unsupported users.id data type for NurseLink: ' . $dataType
                );
        }

        $table->index('user_id');
    }
};

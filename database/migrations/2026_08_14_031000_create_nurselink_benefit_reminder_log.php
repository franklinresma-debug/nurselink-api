<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable(
                'nurselink_benefit_reminder_log'
            )
        ) {
            Schema::create(
                'nurselink_benefit_reminder_log',
                function (Blueprint $table): void {
                    $table->id();
                    $this->userIdDefinition($table);
                    $table->unsignedBigInteger(
                        'benefit_id'
                    );
                    $table->string(
                        'reminder_kind',
                        40
                    );
                    $table->unsignedBigInteger(
                        'notification_id'
                    )->nullable();
                    $table->timestamp(
                        'sent_at'
                    )->nullable();
                    $table->timestamps();

                    $table->unique(
                        [
                            'user_id',
                            'benefit_id',
                            'reminder_kind',
                        ],
                        'nl_benefit_reminder_unique'
                    );
                    $table->index(
                        [
                            'benefit_id',
                            'reminder_kind',
                        ],
                        'nl_benefit_reminder_lookup'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'nurselink_benefit_reminder_log'
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
                    $column
                        ->CHARACTER_MAXIMUM_LENGTH
                    ?: 36
                );
                $table->char(
                    'user_id',
                    max(1, min($length, 255))
                );
                break;
            case 'varchar':
                $length = (int) (
                    $column
                        ->CHARACTER_MAXIMUM_LENGTH
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

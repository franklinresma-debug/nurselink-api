<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const HISTORY = 'nurselink_membership_status_history';
    private const DELIVERY = 'nurselink_membership_notification_deliveries';
    private const DOCUMENTS = 'nurselink_smart_registration_documents';
    private const MEMBERSHIPS = 'nurselink_memberships';

    public function up(): void
    {
        if (! Schema::hasTable(self::HISTORY)) {
            Schema::create(self::HISTORY, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('membership_id');
                $this->userIdDefinition($table);
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40);
                $table->string('actor_user_id', 191)->nullable();
                $table->string('actor_type', 30)->default('system');
                $table->text('reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['membership_id', 'created_at'], 'nl_msh_member_time_idx');
                $table->index(['to_status', 'created_at'], 'nl_msh_status_time_idx');
            });
        }

        if (! Schema::hasTable(self::DELIVERY)) {
            Schema::create(self::DELIVERY, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('membership_id');
                $this->userIdDefinition($table);
                $table->string('event_key', 80);
                $table->string('channel', 30)->default('email');
                $table->string('recipient', 190)->nullable();
                $table->string('status', 30)->default('pending');
                $table->string('subject', 190);
                $table->text('message');
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('last_attempt_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(['membership_id', 'created_at'], 'nl_mnd_member_time_idx');
                $table->index(['status', 'created_at'], 'nl_mnd_status_time_idx');
                $table->index(['event_key', 'created_at'], 'nl_mnd_event_time_idx');
            });
        }

        if (Schema::hasTable(self::MEMBERSHIPS)) {
            Schema::table(self::MEMBERSHIPS, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::MEMBERSHIPS, 'last_status_changed_at')) {
                    $table->timestamp('last_status_changed_at')->nullable()->index();
                }
                if (! Schema::hasColumn(self::MEMBERSHIPS, 'last_status_changed_by')) {
                    $table->string('last_status_changed_by', 191)->nullable();
                }
            });

            DB::table(self::MEMBERSHIPS)
                ->whereNull('last_status_changed_at')
                ->update([
                    'last_status_changed_at' => DB::raw('COALESCE(updated_at, created_at)'),
                ]);
        }

        if (Schema::hasTable(self::DOCUMENTS)) {
            Schema::table(self::DOCUMENTS, function (Blueprint $table): void {
                if (! Schema::hasColumn(self::DOCUMENTS, 'version')) {
                    $table->unsignedInteger('version')->default(1);
                }
                if (! Schema::hasColumn(self::DOCUMENTS, 'is_current')) {
                    $table->boolean('is_current')->default(true);
                }
                if (! Schema::hasColumn(self::DOCUMENTS, 'replaces_document_id')) {
                    $table->unsignedBigInteger('replaces_document_id')->nullable();
                }
                if (! Schema::hasColumn(self::DOCUMENTS, 'replaced_by_document_id')) {
                    $table->unsignedBigInteger('replaced_by_document_id')->nullable();
                }
                if (! Schema::hasColumn(self::DOCUMENTS, 'replaced_at')) {
                    $table->timestamp('replaced_at')->nullable();
                }
            });

            $this->ensureIndex(self::DOCUMENTS, ['user_id', 'is_current'], 'nl_sr_docs_current_idx');
            $this->ensureIndex(self::DOCUMENTS, ['replaces_document_id'], 'nl_sr_docs_replaces_idx');
            $this->ensureIndex(self::DOCUMENTS, ['replaced_by_document_id'], 'nl_sr_docs_replaced_idx');
        }

        $this->backfillCurrentHistory();
    }

    public function down(): void
    {
        if (Schema::hasTable(self::DOCUMENTS)) {
            foreach (['nl_sr_docs_current_idx', 'nl_sr_docs_replaces_idx', 'nl_sr_docs_replaced_idx'] as $index) {
                if ($this->indexExists(self::DOCUMENTS, $index)) {
                    Schema::table(self::DOCUMENTS, fn (Blueprint $table): mixed => $table->dropIndex($index));
                }
            }

            $columns = array_values(array_filter([
                'version',
                'is_current',
                'replaces_document_id',
                'replaced_by_document_id',
                'replaced_at',
            ], fn (string $column): bool => Schema::hasColumn(self::DOCUMENTS, $column)));

            if ($columns !== []) {
                Schema::table(self::DOCUMENTS, fn (Blueprint $table): mixed => $table->dropColumn($columns));
            }
        }

        if (Schema::hasTable(self::MEMBERSHIPS)) {
            $columns = array_values(array_filter([
                'last_status_changed_at',
                'last_status_changed_by',
            ], fn (string $column): bool => Schema::hasColumn(self::MEMBERSHIPS, $column)));

            if ($columns !== []) {
                Schema::table(self::MEMBERSHIPS, fn (Blueprint $table): mixed => $table->dropColumn($columns));
            }
        }

        Schema::dropIfExists(self::DELIVERY);
        Schema::dropIfExists(self::HISTORY);
    }

    private function backfillCurrentHistory(): void
    {
        if (! Schema::hasTable(self::MEMBERSHIPS) || ! Schema::hasTable(self::HISTORY)) return;

        DB::table(self::MEMBERSHIPS)
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                foreach ($rows as $membership) {
                    $exists = DB::table(self::HISTORY)
                        ->where('membership_id', $membership->id)
                        ->exists();

                    if ($exists) continue;

                    DB::table(self::HISTORY)->insert([
                        'membership_id' => (int) $membership->id,
                        'user_id' => $membership->user_id,
                        'from_status' => null,
                        'to_status' => (string) $membership->status,
                        'actor_user_id' => null,
                        'actor_type' => 'system',
                        'reason' => 'Lifecycle history baseline created during v5.5.8 upgrade.',
                        'metadata' => json_encode(['backfilled' => true], JSON_UNESCAPED_SLASHES),
                        'created_at' => $membership->updated_at ?: $membership->created_at ?: now(),
                    ]);
                }
            });
    }

    private function ensureIndex(string $tableName, array $columns, string $indexName): void
    {
        if ($this->indexExists($tableName, $indexName)) return;

        $rows = DB::select(
            "SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            [$tableName]
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[(string) $row->INDEX_NAME][] = (string) $row->COLUMN_NAME;
        }

        foreach ($indexes as $existingColumns) {
            if (array_values($existingColumns) === array_values($columns)) return;
        }

        Schema::table($tableName, fn (Blueprint $table): mixed => $table->index($columns, $indexName));
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 AS present
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1",
            [$tableName, $indexName]
        );
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

        if (! $column) throw new RuntimeException('Unable to inspect users.id.');

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
                throw new RuntimeException('Unsupported users.id data type for NurseLink: ' . $dataType);
        }
    }
};

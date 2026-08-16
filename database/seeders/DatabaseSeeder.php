<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        $this->call(QualificationFrameworkSeeder::class);
        $this->call(CommunicationTemplateSeeder::class);
    }
}

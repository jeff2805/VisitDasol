<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a listing as fictional.
 *
 * The demo seeder creates resorts that are deliberately not real, and once a
 * town has actual resorts registered those two must not sit side by side on
 * the public page. Until now the only thing separating them was the owner's
 * email domain, which meant every check had to reach through the owner
 * relation — and a listing query that had not loaded the owner's email would
 * have failed rather than answered.
 *
 * A column on the resort answers it directly, costs one indexed comparison,
 * and cannot be broken by how a query chose to load its relations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resorts', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('is_featured')->index();
        });

        // Existing demo rows predate the column. They are identified the way
        // they always were, by the owner's email domain, one last time.
        $domain = config('visitdasol.demo.email_domain');

        if (filled($domain)) {
            DB::table('resorts')->whereIn('owner_id', function ($query) use ($domain): void {
                $query->select('id')->from('users')->where('email', 'like', '%@'.$domain);
            })->update(['is_demo' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('resorts', function (Blueprint $table): void {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};

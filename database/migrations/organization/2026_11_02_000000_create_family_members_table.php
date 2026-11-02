<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The people a patient looks after from the app: a child, a parent, a spouse.
 *
 * A LINK, NOT A PERSON. A family member is an ordinary `customers` row — their
 * own patient number, their own history, seen by the desk like anybody else —
 * and this says only whose app answers for them. Taking somebody out of a
 * family deletes this row and leaves the clinic's record of them alone.
 *
 * One family per member, hence the unique index: two logins answering for one
 * child would each be told about, and could cancel, the other's bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_members', function (Blueprint $table) {
            $table->id();

            // Whose family: the patient whose login added them.
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            // The family member's own patient record.
            $table->foreignId('member_id')->unique()->constrained('customers')->cascadeOnDelete();

            $table->string('relation', 20);

            $table->timestamps();

            $table->index('customer_id');
        });

        DB::statement(
            'ALTER TABLE family_members ADD CONSTRAINT family_members_relation_check '.
            "CHECK (relation IN ('spouse', 'child', 'parent', 'sibling', 'other'))"
        );

        DB::statement(
            'ALTER TABLE family_members ADD CONSTRAINT family_members_not_themselves_check '.
            'CHECK (customer_id <> member_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('family_members');
    }
};

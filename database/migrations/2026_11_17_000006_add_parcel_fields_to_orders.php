<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('call_slot')->nullable(); // 10AM 1PM 4PM 7PM (Courier Management)
            $table->string('call_status')->nullable(); // Not called CONNECTED NO ANSWER BUSY SWITCHED OFF WRONG NUMBER
            $table->string('in_charge')->nullable();
            $table->string('return_tag')->nullable(); // §2.4 return taxonomy
            $table->string('claim_status')->nullable(); // Damage & Lost: Pending Paid Rejected
            $table->decimal('claim_amount', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['call_slot', 'call_status', 'in_charge', 'return_tag', 'claim_status', 'claim_amount']);
        });
    }
};

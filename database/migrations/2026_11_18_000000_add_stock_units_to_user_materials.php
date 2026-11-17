<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class AddStockUnitsToUserMaterials extends Migration
{
    public function up()
    {
        // Keep historical quantities, including zero, without guessing their units.
        DB::statement('ALTER TABLE `user_materials`
            MODIFY `stock_amount` DECIMAL(13,3) NULL DEFAULT NULL,
            ADD `stock_unit` VARCHAR(2) NULL DEFAULT NULL AFTER `stock_amount`');
    }

    public function down()
    {
        // Destructive rollback: units are lost, untracked becomes zero, fractions
        // are truncated and quantities outside the old unsigned range are clamped.
        DB::statement('UPDATE `user_materials`
            SET `stock_amount` = LEAST(4294967295, GREATEST(0, FLOOR(COALESCE(`stock_amount`, 0))))');
        DB::statement('ALTER TABLE `user_materials`
            MODIFY `stock_amount` INT UNSIGNED NOT NULL DEFAULT 0,
            DROP COLUMN `stock_unit`');
    }
}

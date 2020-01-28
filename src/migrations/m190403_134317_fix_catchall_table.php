<?php

/**
 * Craft Redirect plugin
 *
 * @author    Venveo
 * @copyright Copyright (c) 2017 dolphiq
 * @copyright Copyright (c) 2019 Venveo
 */

namespace venveo\redirect\migrations;

use craft\db\Migration;
use craft\helpers\MigrationHelper;

/**
 * m190403_134317_fix_catchall_table_and_rebrand migration.
 */
class m190403_134317_fix_catchall_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        // The erroneous table name...
        if ($this->db->tableExists('{{%dolphiq_redirects_catch_all_urls%}}') && !$this->db->tableExists('{{%dolphiq_redirects_catch_all_urls}}')) {
            MigrationHelper::renameTable('{{%dolphiq_redirects_catch_all_urls%}}', '{{%dolphiq_redirects_catch_all_urls}}', $this);
        } elseif ($this->db->tableExists('{{%dolphiq_redirects_catch_all_urls%}}') && $this->db->tableExists('{{%dolphiq_redirects_catch_all_urls}}')) {
            MigrationHelper::dropTable('{{%dolphiq_redirects_catch_all_urls%}}');
        }
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        if ($this->db->tableExists('{{%dolphiq_redirects_catch_all_urls}}')) {
            MigrationHelper::renameTable('{{%dolphiq_redirects_catch_all_urls}}', '{{%dolphiq_redirects_catch_all_urls%}}', $this);
        }
        return true;
    }
}

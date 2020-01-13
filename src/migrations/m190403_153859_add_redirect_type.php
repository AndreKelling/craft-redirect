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

/**
 * m190403_153859_add_redirect_type migration.
 */
class m190403_153859_add_redirect_type extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $this->addColumn('{{%dolphiq_redirects}}', 'type', $this->string('8')->null()->defaultValue('static')->notNull()->after('id'));
        $this->createIndex($this->db->getIndexName('{{%dolphiq_redirects}}', 'type'), '{{%dolphiq_redirects}}', 'type');
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        $this->dropIndex($this->db->getIndexName('{{%dolphiq_redirects}}', 'type'), '{{%dolphiq_redirects}}');
        $this->dropColumn('{{%dolphiq_redirects}}', 'type');
        return true;
    }
}

<?php
declare(strict_types=1);

namespace Payflex\Gateway\Test\Unit\Setup;

/**
 * A drop-in stand-in for Magento's DDL Table builder used by the schema
 * consistency tests. It records every addColumn()/addIndex()/setComment() call
 * so a test can inspect exactly what a Setup script would create — without a
 * database connection and without pulling in the real Table (whose constructor
 * reaches for the global ObjectManager, which is not available in unit tests).
 *
 * Only the fluent methods the module's InstallSchema/UpgradeSchema actually use
 * are implemented; that is deliberate — if a future schema change starts using
 * another Table method the tests will fail loudly rather than silently pass.
 */
class RecordingSchemaTable
{
    /** @var string */
    public $name = '';

    /** @var array<string, array{type:string, size:mixed, options:array, comment:?string}> */
    public $columns = [];

    /** @var array<string, string|array> */
    public $indexes = [];

    /** @var string|null */
    public $comment = null;

    /**
     * @param string $name
     * @param string $type
     * @param mixed  $size
     * @param array  $options
     * @param string|null $comment
     * @return $this
     */
    public function addColumn($name, $type, $size = null, $options = [], $comment = null)
    {
        $this->columns[$name] = [
            'type'    => $type,
            'size'    => $size,
            'options' => is_array($options) ? $options : [],
            'comment' => $comment,
        ];
        return $this;
    }

    /**
     * @param string $indexName
     * @param string|array $fields
     * @param array $options
     * @return $this
     */
    public function addIndex($indexName, $fields, $options = [])
    {
        $this->indexes[$indexName] = $fields;
        return $this;
    }

    /**
     * @param string $comment
     * @return $this
     */
    public function setComment($comment)
    {
        $this->comment = $comment;
        return $this;
    }
}

<?php
namespace Salmon\Migration\ResourceModel\Adapter;

use Salmon\Migration\ResourceModel\AdapterInterface;


class Mysql extends \Migration\ResourceModel\Adapter\Mysql implements AdapterInterface
{
    /**
     * @inheritdoc
     */
    public function getResourceAdapter()
    {
        return $this->resourceAdapter;
    }

    /**
     * @inheritdoc
     */
    public function getDocumentCreateDefinition($documentName)
    {
        $result = [];
        $createTableSql = $this->resourceAdapter->getCreateTable($documentName);
        if(is_string($createTableSql) && strpos($createTableSql, 'CREATE TABLE') === 0) {
            $result['create_query'] = $createTableSql;

            // build queries for fields
            $regExp  = '#\r?\n\s*`(.+)`\s*(.+),#';
            $matches = [];
            preg_match_all($regExp, $createTableSql, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $result['columns'][$match[1]]['create_query'] = $match[2];
            }
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function addFieldToDocument($documentName, $fieldName, $definition)
    {
        return $this->resourceAdapter->addColumn($documentName, $fieldName, $definition);
    }

    /**
     * @inheritdoc
     */
    public function isDocumentExists($documentName)
    {
        return $this->resourceAdapter->isTableExists($documentName);
    }

    /**
     * @inheritdoc
     */
    public function createDocumentByRawDefinition($rawDefinition)
    {
        return $this->resourceAdapter->rawQuery($rawDefinition);
    }
}
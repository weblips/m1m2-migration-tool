<?php
namespace Salmon\Migration\ResourceModel;

interface AdapterInterface extends \Migration\ResourceModel\AdapterInterface
{
    /**
     * Get the resource adapter
     *
     * @return \Magento\Framework\DB\Adapter\Pdo\Mysql
     */
    public function getResourceAdapter();

    /**
     * Retrieve Create Table SQL
     *
     * @param string $documentName
     * @return array
     */
    public function getDocumentCreateDefinition($documentName);

    /**
     * Add a new field to a document
     *
     * @param string $documentName
     * @param string $fieldName
     * @param   array|string $definition  string specific or universal array DB Server definition
     * @return  true|\Zend_Db_Statement_Pdo
     */
    public function addFieldToDocument($documentName, $fieldName, $definition);

    /**
     * Check if a document exists a document
     *
     * @param string $documentName
     * @return  boolean
     */
    public function isDocumentExists($documentName);

    /**
     * Create document using raw definition
     *
     * @param string $rawDefinition
     * @return \Zend_Db_Statement_Interface
     */
    public function createDocumentByRawDefinition($rawDefinition);
}
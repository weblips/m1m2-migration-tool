<?php

namespace Salmon\Migration\Step\DataIntegrity;

use Migration\Config;
use Migration\App\Step\StageInterface;
use Migration\App\ProgressBar\LogLevelProcessor;
use Migration\Logger\Logger;
use Migration\Logger\Manager as LogManager;
use Migration\ResourceModel\Source;
use Migration\ResourceModel\Destination;
use Migration\Step\DatabaseStage;
use Salmon\Migration\Reader\SalmonConfig;
use Salmon\Migration\ResourceModel\Adapter\Mysql as MigrationMysqlAdapter;

/**
 * Class CleanDestination
 */
class CleanDestination extends DatabaseStage implements StageInterface
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var LogLevelProcessor
     */
    protected $progress;

    /**
     * @var Source
     */
    protected $source;

    /**
     * @var Destination
     */
    protected $destination;

    /**
     * @var SalmonConfig
     */
    protected $salmonConfigReader;

    /**
     * @var ModuleContext
     */
    protected $context;

    /**
     * @param Config $config
     * @param Logger $logger
     * @param LogLevelProcessor $progress
     * @param Source $source
     * @param Destination $destination
     * @param SalmonConfig $salmonConfig
     */
    public function __construct(
        Config $config,
        Logger $logger,
        LogLevelProcessor $progress,
        Source $source,
        Destination $destination,
        SalmonConfig $salmonConfig
    ) {
        parent::__construct($config);
        $this->logger = $logger;
        $this->progress = $progress;
        $this->source = $source;
        $this->destination = $destination;
        $this->salmonConfigReader = $salmonConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function perform()
    {
        return $this->_fixDestinationSchemaIssues();
    }

    /**
     * Fix Issues in destination database
     *
     * @return boolean
     */
    protected function _fixDestinationSchemaIssues()
    {
        $this->logger->notice("### Fixing destination schema issues ###");

        $logMessages = [];
        $dstAdapter = $this->getDestinationAdapter();

        $this->progress->start(1, LogManager::LOG_LEVEL_INFO);
        $catalogCategoryProductStructure = $dstAdapter->isDocumentExists('catalog_category_product') ?
            $dstAdapter->getDocumentStructure('catalog_category_product') : false;
        if(!$catalogCategoryProductStructure || !isset($catalogCategoryProductStructure['entity_id'])) {
            $this->recreateCatalogCategoryProductTable();
            $logMessages[] = [
                'type' => Logger::NOTICE,
                'body' => 'Field [entity_id] has been created in the destination document [catalog_category_product].'
            ];
        }

        $this->progress->advance(LogManager::LOG_LEVEL_INFO);
        $this->progress->finish(LogManager::LOG_LEVEL_INFO);

        foreach ($logMessages as $messageInfo) {
            $this->logger->addRecord($messageInfo['type'], $messageInfo['body']);
        }

        return true;
    }

    /**
     * @return MigrationMysqlAdapter
     */
    protected function getSourceAdapter()
    {
        return $this->source->getAdapter();
    }

    /**
     * @return MigrationMysqlAdapter
     */
    protected function getDestinationAdapter()
    {
        return $this->destination->getAdapter();
    }

    /**
     * Recreate table 'catalog_category_product'
     */
    protected function recreateCatalogCategoryProductTable()
    {
        $dbConnection = $this->getDestinationAdapter()->getResourceAdapter();
        $dbConnection->dropTable('catalog_category_product');

        /**
         * Create table 'catalog_category_product'
         */
        $table = $dbConnection->newTable('catalog_category_product')
            ->addColumn(
                'entity_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                null,
                ['identity' => true, 'nullable' => false, 'primary' => true],
                'Entity ID'
            )
            ->addColumn(
                'category_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                null,
                ['unsigned' => true, 'nullable' => false, 'primary' => true, 'default' => '0'],
                'Category ID'
            )
            ->addColumn(
                'product_id',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                null,
                ['unsigned' => true, 'nullable' => false, 'primary' => true, 'default' => '0'],
                'Product ID'
            )
            ->addColumn(
                'position',
                \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                null,
                ['nullable' => false, 'default' => '0'],
                'Position'
            )
            ->addIndex(
                $dbConnection->getIndexName('catalog_category_product', ['product_id']),
                ['product_id']
            )
            ->addIndex(
                $dbConnection->getIndexName(
                    'catalog_category_product',
                    ['category_id', 'product_id'],
                    \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
                ),
                ['category_id', 'product_id'],
                ['type' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
            )
            ->addForeignKey(
                $dbConnection->getForeignKeyName('catalog_category_product', 'product_id', 'catalog_product_entity', 'entity_id'),
                'product_id',
                'catalog_product_entity',
                'entity_id',
                \Magento\Framework\DB\Ddl\Table::ACTION_CASCADE
            )
            ->setComment('Catalog Product To Category Linkage Table');

        $dbConnection->createTable($table);
    }
}

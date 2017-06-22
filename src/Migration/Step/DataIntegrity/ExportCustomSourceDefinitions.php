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
 * Class ExportCustomSourceDefinitions
 */
class ExportCustomSourceDefinitions extends DatabaseStage implements StageInterface
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
        return $this->_exportCustomDocuments() && $this->_exportCustomFields();
    }

    /**
     * Export custom fields
     *
     * @return boolean
     */
    protected function _exportCustomFields()
    {
        $this->logger->notice("### Exporting Custom Fields ###");

        $logMessages = [];
        $srcAdapter = $this->getSourceAdapter();
        $dstAdapter = $this->getDestinationAdapter();
        $fieldsToExport = $this->salmonConfigReader->getSourceFieldsToExport();
        $this->progress->start(count($fieldsToExport), LogManager::LOG_LEVEL_INFO);
        foreach ($fieldsToExport as $exportData) {
            $srcFieldToExport = $exportData['source'];
            $dstFieldToCreate = $exportData['destination'];

            $dstStructure = $dstAdapter->getDocumentStructure($dstFieldToCreate['document']);
            if(!isset($dstStructure[$dstFieldToCreate['field']])) {
                $srcCreateDefinition = $srcAdapter->getDocumentCreateDefinition($srcFieldToExport['document']);
                if(!empty($srcCreateDefinition['columns'][$srcFieldToExport['field']]['create_query'])) {
                    // Create new field in the destination document
                    $fieldCreateDefinition = $srcCreateDefinition['columns'][$srcFieldToExport['field']]['create_query'];
                    $dstAdapter->addFieldToDocument($dstFieldToCreate['document'], $dstFieldToCreate['field'], $fieldCreateDefinition);
                    $logMessages[] = [
                        'type' => Logger::NOTICE,
                        'body' =>
                            sprintf('Field [%s] has been created in the destination document [%s].',
                                $dstFieldToCreate['field'], $dstFieldToCreate['document']
                            )
                    ];
                } else {
                    $logMessages[] = [
                        'type' => Logger::WARNING,
                        'body' =>
                            sprintf('Field [%s] was not found in the source document [%s], this field has been skipped.',
                                $srcFieldToExport['field'], $srcFieldToExport['document']
                            )
                    ];
                }
            } else {
                $logMessages[] = [
                    'type' => Logger::WARNING,
                    'body' =>
                        sprintf('Field [%s] already exists in the destination document [%s], this field has been skipped.',
                            $dstFieldToCreate['field'], $dstFieldToCreate['document']
                        )
                ];
            }
            $this->progress->advance(LogManager::LOG_LEVEL_INFO);
        }
        $this->progress->finish(LogManager::LOG_LEVEL_INFO);

        foreach ($logMessages as $messageInfo) {
            $this->logger->addRecord($messageInfo['type'], $messageInfo['body']);
        }

        return true;
    }

    /**
     * Export custom documents
     *
     * @return boolean
     */
    protected function _exportCustomDocuments()
    {
        $this->logger->notice("### Exporting Custom Documents ###");

        $logMessages = [];
        $srcAdapter = $this->getSourceAdapter();
        $dstAdapter = $this->getDestinationAdapter();

        /* Build export rules */
        $documentsToExport = [];
        foreach($srcAdapter->getDocumentList() as $docName) {
            if ($exportRules = $this->salmonConfigReader->getSourceDocumentExportRules($docName)) {
                foreach($exportRules as $key => $exportRule) {
                    $documentsToExport[$key] = $exportRule;
                }
            }
        }

        /* Start exporting the rules */
        $this->progress->start(count($documentsToExport), LogManager::LOG_LEVEL_INFO);
        foreach ($documentsToExport as $exportRule) {
            $srcDocumentName = $exportRule->getSourceDocumentName();
            $destDocumentName = $exportRule->getDestinationDocumentName();
            if(!$dstAdapter->isDocumentExists($destDocumentName)) {
                $documentCreateDefinition = $srcAdapter->getDocumentCreateDefinition($srcDocumentName);

                if(!empty($documentCreateDefinition['create_query'])) {
                    // Create new document in the destination database
                    $dstAdapter->createDocumentByRawDefinition($documentCreateDefinition['create_query']);
                    $logMessages[] = [
                        'type' => Logger::NOTICE,
                        'body' => sprintf('Document [%s] has been created in the destination database.', $destDocumentName)
                    ];
                } else {
                    $logMessages[] = [
                        'type' => Logger::WARNING,
                        'body' => sprintf('Document [%s] was not found in the source database, this document has been skipped.', $srcDocumentName)
                    ];
                }
            } else {
                $logMessages[] = [
                    'type' => Logger::WARNING,
                    'body' => sprintf('Document [%s] already exists in the destination database, this document has been skipped.', $destDocumentName)
                ];
            }
            $this->progress->advance(LogManager::LOG_LEVEL_INFO);
        }
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
     * @return array
     */
    protected function getSourceDocumentList()
    {
        return $this->getSourceAdapter()->getDocumentList();
    }
}

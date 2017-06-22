<?php

namespace Salmon\Migration\Step\DataIntegrity;

use Migration\Config;
use Migration\App\Step\StageInterface;
use Migration\App\ProgressBar\LogLevelProcessor;
use Migration\Logger\Logger;
use Migration\Logger\Manager as LogManager;
use Migration\ResourceModel\AdapterInterface;
use Migration\ResourceModel\Source;
use Migration\Step\DatabaseStage;
use Migration\Step\DataIntegrity\Model\OrphanRecordsCheckerFactory;
use Migration\Step\DataIntegrity\Model\OrphanRecordsChecker;
use Salmon\Migration\Reader\SalmonConfig;

/**
 * Class CleanSource
 */
class CleanSource extends DatabaseStage implements StageInterface
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
     * @var OrphanRecordsCheckerFactory
     */
    protected $checkerFactory;

    /**
     * @var SalmonConfig
     */
    protected $salmonConfigReader;

    /**
     * @param Config $config
     * @param Logger $logger
     * @param LogLevelProcessor $progress
     * @param Source $source
     * @param OrphanRecordsCheckerFactory $checkerFactory
     */
    public function __construct(
        Config $config,
        Logger $logger,
        LogLevelProcessor $progress,
        Source $source,
        OrphanRecordsCheckerFactory $checkerFactory,
        SalmonConfig $salmonConfig
    ) {
        parent::__construct($config);
        $this->logger = $logger;
        $this->progress = $progress;
        $this->source = $source;
        $this->checkerFactory = $checkerFactory;
        $this->salmonConfigReader = $salmonConfig;
    }

    /**
     * {@inheritdoc}
     */
    public function perform()
    {
        $documentsToClean = $this->salmonConfigReader->getSourceDocumentsToClean();
        $this->progress->start(count($documentsToClean), LogManager::LOG_LEVEL_INFO);

        $logMessages = [];
        foreach ($documentsToClean as $document) {
            foreach ($this->getAdapter()->getForeignKeys($document) as $keyData) {
                /** @var OrphanRecordsChecker $checker */
                $checker = $this->checkerFactory->create($this->getAdapter(), $keyData);
                if ($checker->hasOrphanRecords()) {
                    $logMessages[] = ['type' => Logger::WARNING, 'body' => $this->buildOrphansLogMessage($checker)];
                    $this->cleanDocumentOrphans($checker);
                    $logMessages[] = ['type' => Logger::NOTICE, 'body' => $this->buildOrphansCleanedLogMessage($checker)];
                }
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
     * @return AdapterInterface
     */
    protected function getAdapter()
    {
        return $this->source->getAdapter();
    }

    /**
     * @return array
     */
    protected function getDocumentList()
    {
        return $this->getAdapter()->getDocumentList();
    }

    /**
     * Builds and returns well-formed error message
     *
     * @param OrphanRecordsChecker $checker
     * @return string
     */
    private function buildOrphansLogMessage(OrphanRecordsChecker $checker)
    {
        $nbOfRecords = count($checker->getOrphanRecordsIds());
        return sprintf(
            '%s orphan record%s from `%s`.`%s` found with no referenced record%s in `%s`',
            $nbOfRecords,
            $nbOfRecords > 1 ? 's' : '',
            $checker->getChildTable(),
            $checker->getChildTableField(),
            $nbOfRecords > 1 ? 's' : '',
            $checker->getParentTable()
        );
    }

    /**
     * Delete orphan rows
     *
     * @param OrphanRecordsChecker $checker
     * @return string
     */
    protected function cleanDocumentOrphans($checker)
    {
        if ($checker->getOrphanRecordsIds() && $checker->getChildTable() && $checker->getChildTableField()) {
            $this->source->deleteRecords(
                $checker->getChildTable(), $checker->getChildTableField(), $checker->getOrphanRecordsIds()
            );
        }

        return true;
    }

    /**
     * Builds and returns well-formed message
     *
     * @param OrphanRecordsChecker $checker
     * @return string
     */
    private function buildOrphansCleanedLogMessage(OrphanRecordsChecker $checker)
    {
        $nbOfRecords = count($checker->getOrphanRecordsIds());
        return sprintf(
            'orphan record%s from `%s`.`%s` with no referenced record%s in `%s` %s been deleted',
            $nbOfRecords > 1 ? 's' : '',
            $checker->getChildTable(),
            $checker->getChildTableField(),
            $nbOfRecords > 1 ? 's' : '',
            $checker->getParentTable(),
            $nbOfRecords > 1 ? 'have' : 'has'
        );
    }
}

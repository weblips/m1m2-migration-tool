<?php

namespace Salmon\Migration\Reader;

use Migration\Exception;
use \Magento\Framework\App\Arguments\ValidationState;
use \Magento\Framework\DataObject;

/**
 * Class SalmonConfig
 */
class SalmonConfig
{
    const CONFIGURATION_FILE_OPTION = 'salmon_config_file';
    const CONFIGURATION_SCHEMA = 'salmon-config.xsd';
    const TYPE_SOURCE = 'source';
    const TYPE_DEST = 'destination';

    /**
     * @var \DOMXPath
     */
    protected $xml;

    /**
     * Configuration of application
     *
     * @var \Migration\Config
     */
    protected $config;

    /**
     * @var array
     */
    protected $documentsToExport = null;

    /**
     * @var array
     */
    protected $fieldsToExport = null;

    /**
     * @var array
     */
    protected $wildcards;

    /**
     * @var ValidationState
     */
    protected $validationState;

    /**
     * @param \Migration\Config $config
     * @param ValidationState $validationState
     */
    public function __construct(
        \Migration\Config $config,
        ValidationState $validationState
    ) {
        $this->config = $config;
        $this->validationState = $validationState;
        $configFile = $this->config->getOption(self::CONFIGURATION_FILE_OPTION);
        $this->init($configFile);
    }

    /**
     * Init configuration
     *
     * @param string $configFile
     * @return $this
     * @throws Exception
     */
    protected function init($configFile)
    {
        $this->documentsToExport = null;
        $this->fieldsToExport = null;

        $configFile = $this->getRootDir() . $configFile;
        if (!is_file($configFile)) {
            throw new Exception('Invalid configuration filename: ' . $configFile);
        }

        $xml = file_get_contents($configFile);
        $document = new \Magento\Framework\Config\Dom($xml, $this->validationState);

        $errors = [];
        if (!$document->validate($this->getRootDir() .'etc/' . self::CONFIGURATION_SCHEMA, $errors)) {
            print_r($errors);
            print_r($xml);
            throw new Exception('XML file is invalid.');
        }

        $this->xml = new \DOMXPath($document->getDom());
        return $this;
    }

    /**
     * Get Salmon Migration Tool Configuration Dir
     * @return string
     */
    protected function getRootDir()
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR;
    }


    /**
     * Get list of source documents to clean
     *
     * @return array
     */
    public function getSourceDocumentsToClean()
    {
        $documents = [];
        /** @var \DOMElement $item */
        foreach ($this->xml->query("//source/document_rules/clean_orphans/document") as $item) {
            $documents[$item->nodeValue] = $item->nodeValue;
        }
        return $documents;
    }


    /**
     * Get list of source fields to export
     *
     * @return array
     */
    public function getSourceFieldsToExport()
    {
        $result = [];
        /** @var \DOMElement $item */
        foreach ($this->xml->query("//source/field_rules/export") as $item) {
            $sourceField = $item->getElementsByTagName('field')->item(0);
            $destinationField = $item->getElementsByTagName('to')->item(0);
            if($sourceField && $destinationField) {
                $key = strtolower(trim($destinationField->nodeValue));
                $sourceParts = explode('.', $sourceField->nodeValue);
                $destinationParts = explode('.', $destinationField->nodeValue);
                $result[$key] = [
                    'source' => ['document' => $sourceParts[0], 'field' => $sourceParts[1]],
                    'destination' => ['document' => $destinationParts[0], 'field' => $destinationParts[1]]
                ];
            } else {
                throw new Exception(sprintf('Invalid field export node at line %s', $item->getLineNo()));
            }
        }

        return $result;
    }


    /**
     * Get list of source documents to export (wilcards are excluded)
     *
     * @return array[DataObject]
     */
    public function getSourceDocumentsToExport()
    {
        $result = [];

        /** @var \DOMElement $item */
        foreach ($this->xml->query("//source/document_rules/export") as $item) {
            $sourceDocument = $item->getElementsByTagName('document')->item(0);
            $destinationDocument = $sourceDocument; // module should allow different document names
            if (strpos($sourceDocument->nodeValue, '*') === FALSE) {  // exclude wildcards
                if ($sourceDocument && $destinationDocument
                    && ($exportRule = $this->parseSourceDocumentExportNode($item))) {
                    $key = strtolower($exportRule->getDestinationDocumentName());
                    $result[$key] = $exportRule;
                } else {
                    throw new Exception(sprintf('Invalid document export node at line %s', $item->getLineNo()));
                }
            }
        }

        return $result;
    }


    /**
     * Get export data for a given source documents
     *
     * @param string $documentName
     * @return array[DataObject]
     */
    public function getSourceDocumentExportRules($documentName)
    {
        $key = trim($documentName);
        if (!isset($this->documentsToExport[$key])) {
            /** @var \DOMElement $item */
            $searchResult = $this->xml->query(sprintf('//source/document_rules/export/document[text()="%s"]', $documentName));
            if ($searchResult->length < 1) {
                foreach ($this->getSourceDocumentsExportWildcards() as $documentWildCard) {
                    $regexp = '/^' . str_replace('*', '.+', $documentWildCard->nodeValue) . '/';
                    $result = preg_match($regexp, $documentName) > 0;
                    if ($result === true) {
                        $searchResult = [$documentWildCard];
                        break;
                    }
                }
            }

            $this->documentsToExport[$key] = [];
            foreach ($searchResult as $item) {
                if ($exportRule = $this->parseSourceDocumentExportNode($item->parentNode, $documentName)) {
                    $destKey = strtolower($exportRule->getDestinationDocumentName());
                    $this->documentsToExport[$key][$destKey] = $exportRule;
                }
            }
        }

        return $this->documentsToExport[$key];
    }

    /**
     * Get export data for a given source documents
     *
     * @param \DOMElement $exportNode
     * @param string $documentName
     * @return DataObject | null
     */
    public function parseSourceDocumentExportNode($exportNode, $documentName = null)
    {
        $documentName = trim($documentName);
        $sourceDocument = $exportNode->getElementsByTagName('document')->item(0);
        $isWildCard = !(strpos($sourceDocument->nodeValue, '*') === FALSE);

        if(!($sourceDocument && $sourceDocument->nodeValue) || ($isWildCard && !$documentName)) {
            return null;
        }

        $exportRule = new DataObject();
        if($isWildCard) {
            $exportRule->setSourceDocumentName($documentName);
            $exportRule->setDestinationDocumentName($documentName);
        } else {
            $exportRule->setSourceDocumentName($sourceDocument->nodeValue);
            $exportRule->setDestinationDocumentName($sourceDocument->nodeValue);
        }

        return $exportRule;
    }


    /**
     * @param string $type
     * @return mixed
     */
    protected function getSourceDocumentsExportWildcards()
    {
        $wilcardKey = 'export_documents';
        if ($this->wildcards === null || !isset($this->wildcards[$wilcardKey])) {
            $this->wildcards[$wilcardKey] = [];
            foreach ($this->xml->query('//source/document_rules/export/document[contains (.,"*")]') as $wildcard) {
                $this->wildcards[$wilcardKey][$wildcard->nodeValue] = $wildcard;
            }
        }
        return $this->wildcards[$wilcardKey];
    }
}

<?php

namespace Salmon\Migration\Handler\VisualMerchandiser;

use Migration\ResourceModel\Record;

class SmartAttribute extends \Migration\Handler\VisualMerchandiser\SmartAttribute
{
    /**
     * @var \Magento\Eav\Api\AttributeRepositoryInterface
     */
    protected $eavAttributeRepository;


    /**
     * @param \Magento\Eav\Api\AttributeRepositoryInterface $eavAttribute
     */
    public function __construct(
        \Magento\Eav\Api\AttributeRepositoryInterface $eavAttributeRepository
    ) {
        $this->eavAttributeRepository = $eavAttributeRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function handle(Record $recordToHandle, Record $oppositeRecord)
    {
        $count = 0;
        $attributes = [];
        $this->validate($recordToHandle);
        $attributeCode = $recordToHandle->getValue(self::ATTRIBUTE_CODE_NAME);
        $attributeCodeArr = explode(',', $attributeCode);
        $attributeValues = unserialize($recordToHandle->getValue(self::ATTRIBUTE_VALUE_NAME));
        if (is_array($attributeValues)) {
            foreach ($attributeValues as $attributeValue) {
                $attribute = $this->parseOperator($attributeValue['value']);
                $attribute['attribute'] = isset($attributeCodeArr[$count]) ?
                    $attributeCodeArr[$count] : $this->parseAttributeCode($attributeValue);
                $attribute['logic'] = $attributeValue['link'];
                $count++;
                $attributes[] = $attribute;
            }
            $attributeString = \Zend_Json::encode($attributes);

            $recordToHandle->setValue($this->field, $attributeString);
        }
    }


    /**
     * @param array $attributeInfo
     * @return string
     */
    protected function parseAttributeCode($attributeInfo)
    {
        $attributeCode = $attributeInfo['attribute'];
        if(is_numeric($attributeCode)) {
            $eavAttribute = $this->eavAttributeRepository->get(\Magento\Catalog\Api\Data\ProductAttributeInterface::ENTITY_TYPE_CODE, $attributeCode);
            $attributeCode = $eavAttribute->getAttributeCode();
        }

        return $attributeCode;
    }
}
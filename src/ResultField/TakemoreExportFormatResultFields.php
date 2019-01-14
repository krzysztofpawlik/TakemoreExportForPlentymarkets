<?php

namespace ExportTakemoreNet\ResultField;

use Plenty\Modules\Cloud\ElasticSearch\Lib\ElasticSearch;
use Plenty\Modules\Cloud\ElasticSearch\Lib\Source\Mutator\BuiltIn\LanguageMutator;
use Plenty\Modules\DataExchange\Contracts\ResultFields;
use Plenty\Modules\Helper\Services\ArrayHelper;
use Plenty\Modules\Item\Search\Mutators\BarcodeMutator;
use Plenty\Modules\Item\Search\Mutators\ImageMutator;
use Plenty\Modules\Item\Search\Mutators\KeyMutator;
use Plenty\Modules\Helper\Models\KeyValue;

/**
 * Class ExportFormatResultFields
 * @package PluginExportFormatTutorial\ResultField
 */
class TakemoreExportFormatResultFields extends ResultFields
{
    const DEFAULT_MARKET_REFERENCE = 9;

    /**
     * @var ArrayHelper
     */
    private $arrayHelper;

    /**
     * ExportFormatResultFields constructor.
     * @param ArrayHelper $arrayHelper
     */
    public function __construct(ArrayHelper $arrayHelper)
    {
        $this->arrayHelper = $arrayHelper;
    }

    /**
     * Creates the fields set to be retrieved from ElasticSearch.
     *
     * @param array $formatSettings
     * @return array
     */
    public function generateResultFields(array $formatSettings = []):array
    {
        /** @var KeyValue $settings */
        $settings = $this->arrayHelper->buildMapFromObjectList($formatSettings, 'key', 'value');
        $reference = $settings->get('referrerId') ? $settings->get('referrerId') : self::DEFAULT_MARKET_REFERENCE;

        $this->setOrderByList(['path' => 'variation.itemId', 'order' => ElasticSearch::SORTING_ORDER_ASC]);

        $itemDescriptionFields = ['texts.urlPath'];
        $itemDescriptionFields[] = ($settings->get('nameId')) ? 'texts.name' . $settings->get('nameId') : 'texts.name1';

        if($settings->get('descriptionType') == 'itemShortDescription' || $settings->get('previewTextType') == 'itemShortDescription')
        {
            $itemDescriptionFields[] = 'texts.shortDescription';
        }

        if($settings->get('descriptionType') == 'itemDescription'
            || $settings->get('descriptionType') == 'itemDescriptionAndTechnicalData'
            || $settings->get('previewTextType') == 'itemDescription'
            || $settings->get('previewTextType') == 'itemDescriptionAndTechnicalData')
        {
            $itemDescriptionFields[] = 'texts.description';
        }

        if($settings->get('descriptionType') == 'technicalData'
            || $settings->get('descriptionType') == 'itemDescriptionAndTechnicalData'
            || $settings->get('previewTextType') == 'technicalData'
            || $settings->get('previewTextType') == 'itemDescriptionAndTechnicalData')
        {
            $itemDescriptionFields[] = 'texts.technicalData';
        }

        $itemDescriptionFields[] = 'texts.lang';

        // Mutators
        
        /** @var ImageMutator $imageMutator */
        $imageMutator = pluginApp(ImageMutator::class);
        if($imageMutator instanceof ImageMutator)
        {
            $imageMutator->addMarket($reference);
        }

        /** @var LanguageMutator $languageMutator */
        $languageMutator = pluginApp(LanguageMutator::class, ['languages' => [$settings->get('lang')]]);

		/** @var BarcodeMutator $barcodeMutator */
		$barcodeMutator = pluginApp(BarcodeMutator::class);
		if($barcodeMutator instanceof BarcodeMutator)
		{
			$barcodeMutator->addMarket($reference);
		}

		/** @var KeyMutator */
		$keyMutator = pluginApp(KeyMutator::class);
		if($keyMutator instanceof KeyMutator)
		{
			$keyMutator->setKeyList($this->getKeyList());
			$keyMutator->setNestedKeyList($this->getNestedKeyList());
		}

        // Fields
        $fields = [
            [
                //item
                'item.id',
                'item.manufacturer.id',

                //variation
                'id',
                'variation.availability.id',
                'variation.stockLimitation',
                'variation.vatId',
                'variation.model',
                'variation.isMain',
				'variation.number',

                //images
                'images.all.urlMiddle',
                'images.all.urlPreview',
                'images.all.urlSecondPreview',
                'images.all.url',
                'images.all.path',
                'images.all.position',

                'images.item.urlMiddle',
                'images.item.urlPreview',
                'images.item.urlSecondPreview',
                'images.item.url',
                'images.item.path',
                'images.item.position',

                'images.variation.urlMiddle',
                'images.variation.urlPreview',
                'images.variation.urlSecondPreview',
                'images.variation.url',
                'images.variation.path',
                'images.variation.position',

                //unit
                'unit.content',
                'unit.id',

                //defaultCategories
                'defaultCategories.id',

                //allCategories
                'ids.categories.all',

                //barcodes
                'barcodes.code',
                'barcodes.type',

                //attributes
                'attributes.attributeValueSetId',
                'attributes.attributeId',
                'attributes.valueId',

				//properties
				'properties.property.id',
				'properties.property.valueType',
				'properties.selection.name',
				'properties.selection.lang',
				'properties.texts.value',
				'properties.texts.lang'
            ],
            [
                $languageMutator,
				$barcodeMutator,
				$keyMutator
            ],
        ];

        // Get the associated images if reference is selected
        if($reference != -1)
        {
            $fields[1][] = $imageMutator;
        }

        foreach($itemDescriptionFields as $itemDescriptionField)
        {
            //texts
            $fields[0][] = $itemDescriptionField;
        }

        return $fields;
    }

	/**
     * Returns predefined keys to make sure that they will be available in the feed.
     * 
	 * @return array
	 */
	private function getKeyList()
	{
		return [
			// Item
			'item.id',
			'item.manufacturer.id',
			'item.conditionApi',

			// Variation
			'variation.availability.id',
			'variation.model',
			'variation.releasedAt',
			'variation.stockLimitation',
			'variation.weightG',
			'variation.number',

			// Unit
			'unit.content',
			'unit.id',

			'ids.categories.all',
		];
	}

	/**
     * Returns the predefined nested keys to make sure that they will be available in the feed.
     * 
	 * @return array
	 */
	private function getNestedKeyList()
	{
		return [
			'keys' => [
				// Attributes
				'attributes',

				// Barcodes
				'barcodes',

				// Default categories
				'defaultCategories',

				// Images
				'images.all',
				'images.item',
				'images.variation',
				
				'texts',
				'skus'
			],

			'nestedKeys' => [
				// Attributes
				'attributes' => [
					'attributeValueSetId',
					'attributeId',
					'valueId'
				],

				// Barcodes
				'barcodes' => [
					'code',
					'type'
				],

				// Default categories
				'defaultCategories' => [
					'id'
				],

				// Images
				'images.all' => [
					'urlMiddle',
					'urlPreview',
					'urlSecondPreview',
					'url',
					'path',
					'position',
				],
				'images.item' => [
					'urlMiddle',
					'urlPreview',
					'urlSecondPreview',
					'url',
					'path',
					'position',
				],
				'images.variation' => [
					'urlMiddle',
					'urlPreview',
					'urlSecondPreview',
					'url',
					'path',
					'position',
				],

				// texts
				'texts' => [
					'urlPath',
					'name1',
					'name2',
					'name3',
					'shortDescription',
					'description',
					'technicalData',
					'lang'
				],
                'skus' => [
                    'sku'
                ]
			]
		];
	}
}
?>

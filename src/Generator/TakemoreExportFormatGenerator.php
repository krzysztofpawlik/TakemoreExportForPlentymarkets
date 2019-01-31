<?php

namespace ExportTakemoreNet\Generator;

use ElasticExport\Helper\ElasticExportCoreHelper;
use ElasticExport\Helper\ElasticExportPriceHelper;
use ElasticExport\Helper\ElasticExportStockHelper;
use ElasticExport\Services\FiltrationService;
use ExportTakemoreNet\Helper\PriceHelper;
use Plenty\Modules\DataExchange\Contracts\CSVPluginGenerator;
use Plenty\Modules\Helper\Services\ArrayHelper;
use Plenty\Modules\Helper\Models\KeyValue;
use Plenty\Modules\Item\Search\Contracts\VariationElasticSearchScrollRepositoryContract;
use Plenty\Modules\Item\VariationSalesPrice\Contracts\VariationSalesPriceRepositoryContract;
use Plenty\Modules\Item\VariationProperty\Contracts\VariationPropertyValueRepositoryContract;
use Plenty\Modules\Item\Property\Contracts\PropertyRepositoryContract;
use Plenty\Modules\Item\VariationStock\Contracts\VariationStockRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Item\VariationProperty\Models\VariationPropertyValue;
use Plenty\Modules\Item\VariationStock\Models\VariationStock;
use Plenty\Modules\Item\Property\Models\Property;

/**
 * Class ExportFormatGenerator
 * @package PluginExportFormatTutorial\Generator
 */
class TakemoreExportFormatGenerator extends CSVPluginGenerator
{
	use Loggable;

    private $elasticExportCoreHelper;
	private $elasticExportPriceHelper;
	private $elasticExportStockHelper;
    private $arrayHelper;
    private $filtrationService;
	private $priceHelper;
	private $variationPropertyValueRepositoryContract;
	private $salesPriceSearchRepositoryContract;
	private $variationStockRepositoryContract;
	private $allprops;
	private $propertyRepositoryContract;

    /**
     * ExportFormatGenerator constructor.
     * @param ArrayHelper $arrayHelper
     */
	public function __construct(ArrayHelper $arrayHelper,
		VariationPropertyValueRepositoryContract $variationPropertyValueRepositoryContract,
		VariationStockRepositoryContract $variationStockRepositoryContract,
		PropertyRepositoryContract $propertyRepositoryContract)
    {
        $this->arrayHelper = $arrayHelper;
		/*$this->priceHelper = $priceHelper;
		$this->variationSalesPriceRepositoryContract = $variationSalesPriceRepositoryContract;*/
		$this->variationPropertyValueRepositoryContract = $variationPropertyValueRepositoryContract;
		$this->variationStockRepositoryContract = $variationStockRepositoryContract;
		$this->propertyRepositoryContract = $propertyRepositoryContract;
    }

    /**
     * Generates and populates the data into the CSV file.
     *
     * @param VariationElasticSearchScrollRepositoryContract $elasticSearch
     * @param array $formatSettings
     * @param array $filter
     */
    protected function generatePluginContent($elasticSearch, array $formatSettings = [], array $filter = [])
    {
        $this->elasticExportCoreHelper = pluginApp(ElasticExportCoreHelper::class);
        $this->elasticExportPriceHelper = pluginApp(ElasticExportPriceHelper::class);
		$this->elasticExportStockHelper = pluginApp(ElasticExportStockHelper::class);

        /** @var KeyValue $settings */
		$settings = $this->arrayHelper->buildMapFromObjectList($formatSettings, 'key', 'value');
		
		$this->filtrationService = pluginApp(FiltrationService::class, ['settings' => $settings, 'filterSettings' => $filter]);

		$this->setDelimiter(";");

		$a = $this->propertyRepositoryContract->all();
		$this->allprops = $a->entries;
		$header = [
            'VariationID',
            'VariationNo',
            'Model',
            'Name',
            'Description',
            'Image',
            'Brand',
            'Barcode',
			'Variant',
            'Currency',
			'Price',
			'Quantity',
			json_encode($this->allprops),
		];
		foreach($this->allprops as $prop)
		{
			$header[] = $prop->backendName;
		}

		// add header
		$this->addCSVContent($header);

		if($elasticSearch instanceof VariationElasticSearchScrollRepositoryContract)
		{
			$limitReached = false;
			$lines = 0;
			
			do
			{
				if($limitReached === true)
				{
					break;
				}

				$resultList = $elasticSearch->execute();

				foreach($resultList['documents'] as $variation)
				{
					if($lines == $filter['limit'])
					{
						$limitReached = true;
						break;
					}

					if(is_array($resultList['documents']) && count($resultList['documents']) > 0)
					{
					    // we have to filter params, which we cannot filter by Elasticsearch
						if($this->filtrationService->filter($variation))
						{
							continue;
						}

						try
						{
							$this->buildRow($variation, $settings);
						}
						catch(\Throwable $exception)
						{
							$this->getLogger('ExportTakemoreNet')->logException($exception);
						}
						
						$lines++;
					}
				}
			} while ($elasticSearch->hasNext());
		}
    }

	/**
     * Builds one data row.
     * 
	 * @param array $variation
	 * @param KeyValue $settings
	 */
    private function buildRow($variation, $settings)
	{
		$priceList = $this->elasticExportPriceHelper->getPriceList($variation, $settings, 2, '.');
		$size = $this->elasticExportCoreHelper->getAttributeValueSetShortFrontendName($variation, $settings);
		$color = ""; /* don't know how to get */
		$images = implode(',', $this->elasticExportCoreHelper->getImageListInOrder($variation, $settings, 10, ElasticExportCoreHelper::ALL_IMAGES));
		$properties = $this->variationPropertyValueRepositoryContract->findByVariationId($variation['id']);
		$stockList = $this->variationStockRepositoryContract->listStockByWarehouse($variation['id']);
		if (count($stockList) > 0)
			$stock = $stockList[0]['netStock'];
		else
			$stock = 0;

		$data = [
			'VariationID' => $variation['id'],
			'VariationNo' => $variation['data']['variation']['number'],
			'Model' => $variation['data']['variation']['model'],
			'Name' => $this->elasticExportCoreHelper->getMutatedName($variation, $settings, 256),
			'Description' => $this->elasticExportCoreHelper->getMutatedDescription($variation, $settings, 256),
			'Image' => $images,
			'Brand' => $this->elasticExportCoreHelper->getExternalManufacturerName((int)$variation['data']['item']['manufacturer']['id']),
			'Barcode' => $this->elasticExportCoreHelper->getBarcodeByType($variation, $settings->get('barcode')),
			'Size' => $size,
			'Currency' => $priceList['currency'],
			'Price' => $priceList['price'],
			'Quantity' => $stock
		];
		foreach($this->allprops as $prop)
		{
			array_push($data, $this->GetPropertyValue($properties, $prop->id));
		}

		$this->addCSVContent(array_values($data));
	}

	private function GetPropertyValue($properties, $id)
	{
		foreach($properties as $property)
		{
			if ($property->propertyId == $id)
			{
				if ($property->property->valueType == "float")
					$value = $property->valueFloat;
				else if ($property->property->valueType == "selection")
					$value = $property->propertySelection[0]->name;
				return $value;
			}
		}
	}

}
?>

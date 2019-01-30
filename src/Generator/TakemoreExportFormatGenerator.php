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
/*use Plenty\Modules\Item\SalesPrice\Contracts\SalesPriceSearchRepositoryContract;*/
use Plenty\Modules\Item\Search\Contracts\VariationElasticSearchScrollRepositoryContract;
use Plenty\Modules\Item\VariationSalesPrice\Contracts\VariationSalesPriceRepositoryContract;
use Plenty\Modules\Item\VariationProperty\Contracts\VariationPropertyValueRepositoryContract;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Item\VariationProperty\Models\VariationPropertyValue;
/*use Plenty\Modules\Item\VariationSalesPrice\Models\VariationSalesPrice;
use Plenty\Modules\Item\SalesPrice\Models\SalesPriceSearchRequest;
use Plenty\Modules\Item\SalesPrice\Models\SalesPriceSearchResponse;*/

/**
 * Class ExportFormatGenerator
 * @package PluginExportFormatTutorial\Generator
 */
class TakemoreExportFormatGenerator extends CSVPluginGenerator
{
	use Loggable;

    private $elasticExportCoreHelper;
    private $elasticExportPriceHelper;
    private $arrayHelper;
    private $filtrationService;
	private $priceHelper;
	private $variationPropertyValueRepositoryContract;
	private $salesPriceSearchRepositoryContract;
	private $propertyId;

    /**
     * ExportFormatGenerator constructor.
     * @param ArrayHelper $arrayHelper
     */
    public function __construct(ArrayHelper $arrayHelper, VariationPropertyValueRepositoryContract $variationPropertyValueRepositoryContract/*, VariationSalesPriceRepositoryContract $variationSalesPriceRepositoryContract, SalesPriceSearchRepositoryContract $salesPriceSearchRepositoryContract*/)
    {
        $this->arrayHelper = $arrayHelper;
		/*$this->priceHelper = $priceHelper;
		$this->variationSalesPriceRepositoryContract = $variationSalesPriceRepositoryContract;*/
		$this->variationPropertyValueRepositoryContract = $variationPropertyValueRepositoryContract;
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

        /** @var KeyValue $settings */
		$settings = $this->arrayHelper->buildMapFromObjectList($formatSettings, 'key', 'value');
		
		$this->filtrationService = pluginApp(FiltrationService::class, ['settings' => $settings, 'filterSettings' => $filter]);

		$this->setDelimiter(";");
		
		// add header
		$this->addCSVContent([
            'VariationID',
            'VariationNo',
            'Model',
            'Name',
            'Description',
            'Image',
            'Brand',
            'Barcode',
			'Size',
			'Property1',
			'Property2',
			'Property3',
			'Property4',
			'Property5',
            'Currency',
            'Price'
		]);

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

		/* unnecessary $basePriceList = $this->elasticExportPriceHelper->getBasePriceDetails($variation, (float) $priceList['price'], $settings->get('lang'));
		$deliveryCost = $this->elasticExportCoreHelper->getShippingCost($variation['data']['item']['id'], $settings); */

		$size = $this->elasticExportCoreHelper->getAttributeValueSetShortFrontendName($variation, $settings);
		$color = ""; /* don't know how to get */
		$images = implode(',', $this->elasticExportCoreHelper->getImageListInOrder($variation, $settings, 10, ElasticExportCoreHelper::ALL_IMAGES));
		/*$price3 = $this->variationSalesPriceRepositoryContract->findByVariationIdWithInheritance($variation['id']);*/
		$properties = $this->variationPropertyValueRepositoryContract->findByVariationId($variation['id']);

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
			'Property1' => $this->GetPropertyValue($properties, 0),
			'Property2' => $this->GetPropertyValue($properties, 1),
			'Property3' => $this->GetPropertyValue($properties, 2),
			'Property4' => $this->GetPropertyValue($properties, 3),
			'Property5' => $this->GetPropertyValue($properties, 4),
			'Currency' => $priceList['currency'],
			'Price' => $priceList['price']
		];

		$this->addCSVContent(array_values($data));
	}

	private function GetPropertyValue($properties, $index)
	{
		foreach($properties as $property)
		{
			if (!array_key_exists($property->propertyId, $this->propertyId))
			{
				$i = (is_array($this->propertyId)) ? count($this->propertyId) : 0;
				$this->propertyId[$property->propertyId] = $i;
			}
			else
				$i = this->propertyId[$property->propertyId];
			if ($index == $i)
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

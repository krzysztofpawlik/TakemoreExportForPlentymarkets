<?php

namespace ExportTakemoreNet\Generator;

use ElasticExport\Helper\ElasticExportCoreHelper;
use ElasticExport\Helper\ElasticExportPriceHelper;
use ElasticExport\Helper\ElasticExportStockHelper;
use ElasticExport\Services\FiltrationService;
use ExportTakemoreNet\Helper\AttributeHelper;
use Plenty\Modules\DataExchange\Contracts\CSVPluginGenerator;
use Plenty\Modules\Helper\Services\ArrayHelper;
use Plenty\Modules\Helper\Models\KeyValue;
use Plenty\Modules\Item\Search\Contracts\VariationElasticSearchScrollRepositoryContract;
use Plenty\Plugin\Log\Loggable;

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
	private $attributeHelper;

    /**
     * ExportFormatGenerator constructor.
     * @param ArrayHelper $arrayHelper
     */
    public function __construct(ArrayHelper $arrayHelper, AttributeHelper $attributeHelper)
    {
        $this->arrayHelper = $arrayHelper;
		$this->attributeHelper = $attributeHelper;
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
		
		$this->attributeHelper->setPropertyHelper(); /* Is it possible to move this call to the AttributeHelper constructor? */

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
			'Color',
            'Currency',
            'ShippingCosts',
            'RRP',
            'Price',
            'SalePrice'
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

		if((float)$priceList['recommendedRetailPrice'] > 0)
		{
			$price = $priceList['recommendedRetailPrice'] > $priceList['price'] ? $priceList['price'] : $priceList['recommendedRetailPrice'];
		}
		else
		{
			$price = $priceList['price'];
		}
		$salePrice = $priceList['salePrice'];

		$rrp = $priceList['recommendedRetailPrice'] > $priceList['price'] ? $priceList['recommendedRetailPrice'] : $priceList['price'];
		
		if((float)$rrp == 0 || (float)$price == 0 || (float)$rrp == (float)$price)
		{
			$rrp = '';
		}

		/* unnecessary $basePriceList = $this->elasticExportPriceHelper->getBasePriceDetails($variation, (float) $priceList['price'], $settings->get('lang'));
		$deliveryCost = $this->elasticExportCoreHelper->getShippingCost($variation['data']['item']['id'], $settings); */

		$variationAttributes = $this->attributeHelper->getVariationAttributes($variation, $settings);
		$size = $variationAttributes[AttributeHelper::CHARACTER_TYPE_SIZE];
		$color = $variationAttributes[AttributeHelper::CHARACTER_TYPE_SIZE];
		$this->getLogger(__METHOD__)->debug('ExportTakemoreNet::log.size', ['variationAttributes' => $variationAttributes]);
		$this->getLogger(__METHOD__)->debug('ExportTakemoreNet::log.price', ['priceList' => $priceList]);
		$this->getLogger(__METHOD__)->info('ExportTakemoreNet::log.size', ['variationAttributes' => $variationAttributes]);
		$this->getLogger(__METHOD__)->info('ExportTakemoreNet::log.price', ['priceList' => $priceList]);
		$images = implode(',', $this->elasticExportCoreHelper->getImageListInOrder($variation, $settings, 10, ElasticExportCoreHelper::ALL_IMAGES));

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
			'Color' => $color,
			'Currency' => $priceList['currency'],
			'RRP' => $rrp,
			'Price' => $price,
			'SalePrice' => implode('!', $priceList)
		];

		$this->addCSVContent(array_values($data));
	}
}
?>

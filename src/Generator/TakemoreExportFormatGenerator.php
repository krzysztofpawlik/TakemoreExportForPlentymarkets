<?

namespace ExportTakemoreNet\Generator;

use ElasticExport\Helper\ElasticExportCoreHelper;
use ElasticExport\Helper\ElasticExportPriceHelper;
use ElasticExport\Helper\ElasticExportStockHelper;
use ElasticExport\Services\FiltrationService;
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

    /**
     * @var ElasticExportCoreHelper $elasticExportCoreHelper
     */
    private $elasticExportCoreHelper;

	/**
	 * @var ElasticExportPriceHelper $elasticExportPriceHelper
	 */
    private $elasticExportPriceHelper;

    /**
     * @var ArrayHelper $arrayHelper
     */
    private $arrayHelper;

    /**
     * @var FiltrationService
     */
    private $filtrationService;

    /**
     * ExportFormatGenerator constructor.
     * @param ArrayHelper $arrayHelper
     */
    public function __construct(ArrayHelper $arrayHelper)
    {
        $this->arrayHelper = $arrayHelper;
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
            'Currency',
            'ShippingCosts',
            'RRP',
            'Price',
            'BasePrice',
            'BasePriceUnit',
            'Link'
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
							$this->getLogger('PluginExportFormatTutorial')->logException($exception);
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

		$rrp = $priceList['recommendedRetailPrice'] > $priceList['price'] ? $priceList['recommendedRetailPrice'] : $priceList['price'];
		
		if((float)$rrp == 0 || (float)$price == 0 || (float)$rrp == (float)$price)
		{
			$rrp = '';
		}

		$basePriceList = $this->elasticExportPriceHelper->getBasePriceDetails($variation, (float) $priceList['price'], $settings->get('lang'));
		$deliveryCost = $this->elasticExportCoreHelper->getShippingCost($variation['data']['item']['id'], $settings);
		
		if(!is_null($deliveryCost))
		{
			$deliveryCost = number_format((float)$deliveryCost, 2, '.', '');
		}
		else
		{
			$deliveryCost = '';
		}

		$data = [
			'VariationID' => $variation['id'],
			'VariationNo' => $variation['data']['variation']['number'],
			'Model' => $variation['data']['variation']['model'],
			'Name' => $this->elasticExportCoreHelper->getMutatedName($variation, $settings, 256),
			'Description' => $this->elasticExportCoreHelper->getMutatedDescription($variation, $settings, 256),
			'Image' => $this->elasticExportCoreHelper->getImageListInOrder($variation, $settings, 1, ElasticExportCoreHelper::ALL_IMAGES),
			'Brand' => $this->elasticExportCoreHelper->getExternalManufacturerName((int)$variation['data']['item']['manufacturer']['id']),
			'Barcode' => $this->elasticExportCoreHelper->getBarcodeByType($variation, $settings->get('barcode')),
			'Currency' => $priceList['currency'],
			'ShippingCosts' => $deliveryCost,
			'RRP' => $rrp,
			'Price' => $price,
			'BasePrice' => $this->elasticExportPriceHelper->getBasePrice($variation, $priceList['price'], $settings->get('lang'), '/', false, false, $priceList['currency']),
			'BasePriceUnit' => $basePriceList['lot'],
			'Link' => $this->elasticExportCoreHelper->getMutatedUrl($variation, $settings),
		];

		$this->addCSVContent(array_values($data));
	}
}
?>

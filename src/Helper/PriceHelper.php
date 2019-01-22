<?php

namespace ElasticExportRakutenDE\Helper;

use Plenty\Modules\Item\SalesPrice\Contracts\SalesPriceRepositoryContract;
use Plenty\Modules\Item\SalesPrice\Contracts\SalesPriceSearchRepositoryContract;
use Plenty\Modules\Item\SalesPrice\Models\SalesPrice;
use Plenty\Modules\Item\SalesPrice\Models\SalesPriceSearchRequest;
use Plenty\Modules\Helper\Models\KeyValue;
use Plenty\Modules\Item\SalesPrice\Models\SalesPriceSearchResponse;
use Plenty\Modules\Order\Currency\Contracts\CurrencyConversionSettingsRepositoryContract;
use Plenty\Modules\Order\Currency\Contracts\CurrencyRepositoryContract;

class PriceHelper
{
	const TRANSFER_RRP_YES = 1;
	const TRANSFER_OFFER_PRICE_YES = 1;
	const NET_PRICE = 'netPrice';
	const GROSS_PRICE = 'grossPrice';

	/**
	 * @var SalesPriceSearchRepositoryContract
	 */
	private $salesPriceSearchRepositoryContract;
	
	/**
	 * @var CurrencyRepositoryContract
	 */
	private $currencyRepositoryContract;
	
	/**
	 * @var SalesPriceRepositoryContract
	 */
	private $salesPriceRepository;

	/**
	 * @var array
	 */
	private $salesPriceCurrencyList = [];

	/**
	 * @var array
	 */
	private $currencyConversionList = [];
	
	/**
	 * @var CurrencyConversionSettingsRepositoryContract
	 */
	private $currencyConversionSettingsRepositoryContract;

	/**
	 * PriceHelper constructor.
	 * @param SalesPriceSearchRepositoryContract $salesPriceSearchRepositoryContract
	 * @param CurrencyRepositoryContract $currencyRepositoryContract
	 * @param SalesPriceRepositoryContract $salesPriceRepository
	 * @param CurrencyConversionSettingsRepositoryContract $currencyConversionSettingsRepositoryContract
	 */
	public function __construct(
		SalesPriceSearchRepositoryContract $salesPriceSearchRepositoryContract,
		CurrencyRepositoryContract $currencyRepositoryContract,
		SalesPriceRepositoryContract $salesPriceRepository, 
		CurrencyConversionSettingsRepositoryContract $currencyConversionSettingsRepositoryContract)
	{
		$this->salesPriceSearchRepositoryContract = $salesPriceSearchRepositoryContract;
		$this->currencyRepositoryContract = $currencyRepositoryContract;
		$this->salesPriceRepository = $salesPriceRepository;
		$this->currencyConversionSettingsRepositoryContract = $currencyConversionSettingsRepositoryContract;
	}

	/**
	 * Get a List of price, reduced price and the reference for the reduced price.
	 * @param array $variation
	 * @param KeyValue $settings
	 * @return SalesPriceSearchResponse
	 */
	public function getPrice($variation, $settings)
	{
		//getting the retail price
		/**
		 * SalesPriceSearchRequest $salesPriceSearchRequest
		 */
		$salesPriceSearchRequest = pluginApp(SalesPriceSearchRequest::class);
		if($salesPriceSearchRequest instanceof SalesPriceSearchRequest)
		{
			$countryId = $settings->get('destination');
			$currency = $this->currencyRepositoryContract->getCountryCurrency($countryId)->currency;

			$salesPriceSearchRequest->variationId = $variation['id'];
			$salesPriceSearchRequest->referrerId = $settings->get('referrerId');
			$salesPriceSearchRequest->plentyId = $settings->get('plentyId');
			$salesPriceSearchRequest->type = 'default';
			$salesPriceSearchRequest->countryId = $countryId;
			$salesPriceSearchRequest->currency = $currency;
		}

		$salesPriceSearch  = $this->salesPriceSearchRepositoryContract->search($salesPriceSearchRequest);

		return $salesPriceSearch;
	}

	/**
	 * Get a List of price, reduced price and the reference for the reduced price.
	 * @param array $item
	 * @param KeyValue $settings
	 * @return array
	 */
	public function getPriceList($item, KeyValue $settings):array
	{
		$countryId = $settings->get('destination');
		$currency = $this->currencyRepositoryContract->getCountryCurrency($countryId)->currency;
		$variationPriceUpdatedTimestamp = '';
		$rrpUpdatedTimestamp = '';
		$specialPriceUpdatedTimestamp = '';

		if(!is_null($settings->get('liveConversion')) &&
			$settings->get('liveConversion') == true &&
			count($this->currencyConversionList) == 0)
		{
			$this->currencyConversionList = $this->currencyConversionSettingsRepositoryContract->getCurrencyConversionList();
		}

		//getting the retail price
		/**
		 * SalesPriceSearchRequest $salesPriceSearchRequest
		 */
		$salesPriceSearchRequest = pluginApp(SalesPriceSearchRequest::class);
		if($salesPriceSearchRequest instanceof SalesPriceSearchRequest)
		{
			$salesPriceSearchRequest->variationId = $item['id'];
			$salesPriceSearchRequest->referrerId = $settings->get('referrerId');
			$salesPriceSearchRequest->plentyId = $settings->get('plentyId');
			$salesPriceSearchRequest->type = 'default';
			$salesPriceSearchRequest->countryId = $countryId;
			$salesPriceSearchRequest->currency = $currency;
		}

		$salesPriceSearch  = $this->salesPriceSearchRepositoryContract->search($salesPriceSearchRequest);

		$variationPrice = $this->getPriceByRetailPriceSettings($salesPriceSearch, $settings);
		$variationPriceUpdatedTimestamp = $salesPriceSearch->updatedAt;
		
		$vatValue = $salesPriceSearch->vatValue;

		//getting the recommended retail price
		if($settings->get('transferRrp') == self::TRANSFER_RRP_YES)
		{
			$salesPriceSearchRequest->type = 'rrp';
			
			$salesPriceSearch = $this->salesPriceSearchRepositoryContract->search($salesPriceSearchRequest);

			$variationRrp = $this->getPriceByRetailPriceSettings($salesPriceSearch, $settings);
			$rrpUpdatedTimestamp = $salesPriceSearch->updatedAt;
		}
		else
		{
			$variationRrp = 0.00;
		}

		//getting the special price
		if($settings->get('transferOfferPrice') == self::TRANSFER_OFFER_PRICE_YES)
		{
			$salesPriceSearchRequest->type = 'specialOffer';
			$salesPriceSearch = $this->salesPriceSearchRepositoryContract->search($salesPriceSearchRequest);

			$variationSpecialPrice = $this->getPriceByRetailPriceSettings($salesPriceSearch, $settings);
			$specialPriceUpdatedTimestamp = $salesPriceSearch->updatedAt;
		}
		else
		{
			$variationSpecialPrice = 0.00;
		}

		//setting retail price as selling price without a reduced price
		$price = $variationPrice;
		$reducedPrice = '';
		$referenceReducedPrice = '';
		$reducedPriceUpdatedTimestamp = '';
		
		if ($price != '' || $price != 0.00)
		{
			//if recommended retail price is set and higher than retail price...
			if ($variationRrp > 0 && $variationRrp > $variationPrice)
			{
				//set recommended retail price as selling price
				$price = $variationRrp;
				//set retail price as reduced price
				$reducedPrice = $variationPrice;
				//set recommended retail price as reference
				$referenceReducedPrice = 'UVP';
				
				$reducedPriceUpdatedTimestamp = $variationPriceUpdatedTimestamp;
				$variationPriceUpdatedTimestamp = $rrpUpdatedTimestamp;
			}

			// if special offer price is set and lower than retail price and recommended retail price is already set as reference...
			if ($variationSpecialPrice > 0 && $variationPrice > $variationSpecialPrice && $referenceReducedPrice == 'UVP')
			{
				//set special offer price as reduced price
				$reducedPrice = $variationSpecialPrice;
				$reducedPriceUpdatedTimestamp = $specialPriceUpdatedTimestamp;
			}
			//if recommended retail price is not set as reference then ...
			elseif ($variationSpecialPrice > 0 && $variationPrice > $variationSpecialPrice)
			{
				//set special offer price as reduced price and...
				$reducedPrice = $variationSpecialPrice;
				$reducedPriceUpdatedTimestamp = $specialPriceUpdatedTimestamp;
				//set retail price as reference
				$referenceReducedPrice = 'VK';
			}
		}
		return array(
			'price'                     		=> $price,
			'reducedPrice'              		=> $reducedPrice,
			'referenceReducedPrice'     		=> $referenceReducedPrice,
			'vatValue'                  		=> $vatValue,
			'variationPriceUpdatedTimestamp' 	=> $variationPriceUpdatedTimestamp,
			'reducedPriceUpdatedTimestamp' 		=> $reducedPriceUpdatedTimestamp
		);
	}

	/**
	 * Gets the price by the format setting for the retail price.
	 *
	 * @param SalesPriceSearchResponse $salesPriceSearch
	 * @param KeyValue $settings
	 * @return string
	 */
	private function getPriceByRetailPriceSettings(SalesPriceSearchResponse $salesPriceSearch, KeyValue $settings)
	{
		if(isset($salesPriceSearch->price) &&
			($settings->get('retailPrice') == self::GROSS_PRICE || is_null($settings->get('retailPrice'))))
		{
			$price = $this->calculatePriceByCurrency($salesPriceSearch, $salesPriceSearch->price, $settings);
			return $price;
		}
		elseif(isset($salesPriceSearch->priceNet) && $settings->get('retailPrice') == self::NET_PRICE)
		{
			$priceNet = $this->calculatePriceByCurrency($salesPriceSearch, $salesPriceSearch->priceNet, $settings);
			return $priceNet;
		}

		return '';
	}

	/**
	 * Gets the calculated price for a given currency.
	 *
	 * @param SalesPriceSearchResponse $salesPriceSearch
	 * @param $price
	 * @param KeyValue $settings
	 * @return mixed
	 */
	private function calculatePriceByCurrency(SalesPriceSearchResponse $salesPriceSearch, $price, KeyValue $settings)
	{
		if(!is_null($settings->get('liveConversion')) &&
			$settings->get('liveConversion') == true &&
			count($this->currencyConversionList) > 0 &&
			$price > 0)
		{
			if(array_key_exists($salesPriceSearch->salesPriceId, $this->salesPriceCurrencyList) &&
				$this->salesPriceCurrencyList[$salesPriceSearch->salesPriceId] === true)
			{
				$price = $price * $this->currencyConversionList['list'][$salesPriceSearch->currency]['exchange_ratio'];
				return $price;
			}
			elseif(array_key_exists($salesPriceSearch->salesPriceId, $this->salesPriceCurrencyList) &&
				$this->salesPriceCurrencyList[$salesPriceSearch->salesPriceId] === false)
			{
				return $price;
			}

			$salesPriceData = $this->salesPriceRepository->findById($salesPriceSearch->salesPriceId);

			if($salesPriceData instanceof SalesPrice)
			{
				$salePriceCurrencyData = $salesPriceData->currencies->whereIn('currency', [$this->currencyConversionList['default'], "-1"]);

				if(count($salePriceCurrencyData))
				{
					$this->salesPriceCurrencyList[$salesPriceSearch->salesPriceId] = true;

					$price = $price * $this->currencyConversionList['list'][$salesPriceSearch->currency]['exchange_ratio'];

					return $price;
				}
				else
				{
					$this->salesPriceCurrencyList[$salesPriceSearch->salesPriceId] = false;
				}
			}
		}

		return $price;
	}
}
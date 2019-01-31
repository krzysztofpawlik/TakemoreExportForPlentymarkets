<?php
namespace ExportTakemoreNet\Migrations;

use Plenty\Modules\Order\Referrer\Contracts\OrderReferrerRepositoryContract;

/**
 * Class CreateOrderReferrer
 */
class CreateOrderReferrer
{
	/**
	 * @param OrderReferrerRepositoryContract $orderReferrerRepo
	 */
	public function run(OrderReferrerRepositoryContract $orderReferrerRepo)
	{
		$orderReferrer = $orderReferrerRepo->create([
			                                            'editable'    => false,
			                                            'backendName' => 'Takemore',
			                                            'name'        => 'Takemore',
			                                            'origin'      => 'Takemore',
		                                            ]);
	}
}
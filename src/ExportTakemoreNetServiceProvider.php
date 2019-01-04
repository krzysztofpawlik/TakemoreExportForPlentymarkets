<?php

namespace ExportTakemoreNet;

use Plenty\Modules\DataExchange\Services\ExportPresetContainer;
use Plenty\Plugin\ServiceProvider;

/**
 * Class ExportFormatServiceProvider
 * @package PluginExportFormatTutorial
 */
class ExportTakemoreNetServiceProvider extends ServiceProvider
{
    /**
     * Abstract function for registering the service provider.
     */
    public function register()
    {

    }

    /**
     * Adds the export format to the export container.
     *
     * @param ExportPresetContainer $container
     */
    public function boot(ExportPresetContainer $container)
    {
        $container->add(
            'TakemoreNet-plugin',
            'ExportTakemoreNet\ResultField\TakemoreExportFormatResultFields',
            'ExportTakemoreNet\Generator\TakemoreExportFormatGenerator',
            '',
            true,
			true,
            'item'
        );
    }
}
?>

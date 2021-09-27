<?php
/*
 * This file is part of the Arbor API Bundle.
 *
 * Copyright 2021 Robert Woodward.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Robwdwd\ArborApiBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class ArborApiExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');

        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $definitionWS = $container->getDefinition('robwdwd_arbor_api.ws');
        $definitionWS->setArgument(2, $config);

        $definitionSOAP = $container->getDefinition('robwdwd_arbor_api.soap');
        $definitionSOAP->setArgument(1, $config);

        $definitionRS = $container->getDefinition('robwdwd_arbor_api.rest.rest');
        $definitionRS->setArgument(2, $config);

        $definitionRSMO = $container->getDefinition('robwdwd_arbor_api.rest.managed_object');
        $definitionRSMO->setArgument(2, $config);

        $definitionRSMT = $container->getDefinition('robwdwd_arbor_api.rest.mitigation_template');
        $definitionRSMT->setArgument(2, $config);

        $definitionRSNG = $container->getDefinition('robwdwd_arbor_api.rest.notification_group');
        $definitionRSNG->setArgument(2, $config);

        $definitionRSTQ = $container->getDefinition('robwdwd_arbor_api.rest.traffic_query');
        $definitionRSTQ->setArgument(2, $config);
    }
}

<?php

namespace APY\DataGridBundle\Grid\Export;

use Symfony\Component\DependencyInjection\ContainerInterface;

interface ContainerAwareInterface
{
    public function setContainer(?ContainerInterface $container);
}

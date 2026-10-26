<?php
/**
 * Product video export registration.
 *
 * Copyright Grupo Dico. All rights reserved.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'LeanCommerce_ProductVideoExport',
    __DIR__
);

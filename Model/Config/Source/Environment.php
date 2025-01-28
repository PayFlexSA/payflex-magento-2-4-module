<?php

namespace Payflex\Gateway\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

class Environment implements ArrayInterface
{
    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => 0, 'label' => __('Sandbox')],
            ['value' => 1, 'label' => __('Production')]
        ];
    }
}
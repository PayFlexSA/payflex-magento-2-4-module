<?php

namespace Payflex\Gateway\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;

class Support extends AbstractHelper
{
    /**
     * @var \Payflex\Gateway\Helper\Communication
     */
    private $communication;



    public function __construct()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $context = $objectManager->get('Magento\Framework\App\Helper\Context');

        $date = $objectManager->get('Magento\Framework\Stdlib\DateTime\DateTime');
        $this->communication = new Communication($context, $date);
    }

    public function getMagentoVersion()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
        return $productMetadata->getVersion();
    }

    public function getModuleVersion()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $moduleList = $objectManager->get('Magento\Framework\Module\ModuleListInterface');
        return $moduleList->getOne('Payflex_Gateway')['setup_version'];
    }

    public function getPHPVersion()
    {
        return phpversion();
    }

    public function checkValidPayflexCredentials()
    {
        return TRUE;
    }

    public function getAccessToken()
    {
        $this->communication->forceRefeshToken();
        $accessToken = $this->communication->getAccessToken();
        if($accessToken == null)
        {
            $this->communication->forceRefeshToken();
            $accessToken = $this->communication->getAccessToken();
        }

        if($accessToken == null)
        {
            return FALSE;
        }

        return $this->communication->getAccessToken();
    }

    public function getFormattedPayflexEnvironment()
    {
        $environment = $this->communication->environments;

        // var_dump($environment);
        
        // Format array into html string
        $html = "<div class='environment-table'>";
        foreach($environment as $env => $data)
        {
            $html .= "<div class='environment-row'>";
            $html .= "<div class='env-detail-row'>";
            $html .= "<span class='env-header'>Environment</span>";
            $html .= "<span class='env-detail'>: $env</span>";
            $html .= "</div>";
            foreach($data as $key => $value)
            {
                $html .= "<div class='env-detail-row'>";
                $html .= "<span class='env-header'>$key</span>";
                $html .= "<span class='env-detail'>: $value</span>";
                $html .= "</div>";
            }
            $html .= "</div class='environment-row'>";;
        }
        $html .= "</div>";

        return $html;
    }

    public function validAccessToken()
    {
        $accessToken = $this->communication->getAccessToken();
        if($accessToken == null || $accessToken == "")
        {
            return FALSE;
        }

        return TRUE;
    }
}

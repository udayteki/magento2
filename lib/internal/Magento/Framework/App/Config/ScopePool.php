<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\App\Config;

use Magento\Framework\App\RequestInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ScopePool
{
    const CACHE_TAG = 'config_scopes';

    /**
     * @var \Magento\Framework\App\Config\Scope\ReaderPoolInterface
     */
    protected $_readerPool;

    /**
     * @var DataFactory
     */
    protected $_dataFactory;

    /**
     * @var \Magento\Framework\Cache\FrontendInterface
     */
    protected $_cache;

    /**
     * @var string
     */
    protected $_cacheId;

    /**
     * @var DataInterface[]
     */
    protected $_scopes = [];

    /**
     * @var \Magento\Framework\App\ScopeResolverPool
     */
    protected $_scopeResolverPool;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @param \Magento\Framework\App\Config\Scope\ReaderPoolInterface $readerPool
     * @param DataFactory $dataFactory
     * @param \Magento\Framework\Cache\FrontendInterface $cache
     * @param \Magento\Framework\App\ScopeResolverPool $scopeResolverPool
     * @param string $cacheId
     */
    public function __construct(
        \Magento\Framework\App\Config\Scope\ReaderPoolInterface $readerPool,
        DataFactory $dataFactory,
        \Magento\Framework\Cache\FrontendInterface $cache,
        \Magento\Framework\App\ScopeResolverPool $scopeResolverPool,
        $cacheId = 'default_config_cache'
    ) {
        $this->_readerPool = $readerPool;
        $this->_dataFactory = $dataFactory;
        $this->_cache = $cache;
        $this->_cacheId = $cacheId;
        $this->_scopeResolverPool = $scopeResolverPool;
    }

    /**
     * @return RequestInterface
     */
    private function getRequest()
    {
        if ($this->request === null) {
            $this->request = \Magento\Framework\App\ObjectManager::getInstance()->get(RequestInterface::class);
        }
        return $this->request;
    }
    
    /**
     * Retrieve config section
     *
     * @param string $scopeType
     * @param string|\Magento\Framework\DataObject|null $scopeCode
     * @return \Magento\Framework\App\Config\DataInterface
     */
    public function getScope($scopeType, $scopeCode = null)
    {
        $scopeCode = $this->_getScopeCode($scopeType, $scopeCode);

        $code = $scopeType . '|' . $scopeCode;

        if (!isset($this->_scopes[$code])) {
            // Key by url to support dynamic {{base_url}} and port assignments
            $host = $this->getRequest()->getHttpHost();
            $port = $this->getRequest()->getServer('SERVER_PORT');
            $path = $this->getRequest()->getBasePath();

            $urlInfo = $host . $port . trim($path, '/');
            $cacheKey = $this->_cacheId . '|' . $code . '|' . $urlInfo;
            $data = $this->_cache->load($cacheKey);

            if ($data) {
                $data = \Zend_Json::decode($data);
            } else {
                $reader = $this->_readerPool->getReader($scopeType);
                if ($scopeType === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
                    $data = $reader->read();
                } else {
                    $data = $reader->read($scopeCode);
                }
                $this->_cache->save(\Zend_Json::encode($data), $cacheKey, [self::CACHE_TAG]);
            }
            $this->_scopes[$code] = $this->_dataFactory->create(['data' => $data]);
        }
        return $this->_scopes[$code];
    }

    /**
     * Clear cache of all scopes
     *
     * @return void
     */
    public function clean()
    {
        $this->_scopes = [];
        $this->_cache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [self::CACHE_TAG]);
    }

    /**
     * Retrieve scope code value
     *
     * @param string $scopeType
     * @param string|\Magento\Framework\DataObject|null $scopeCode
     * @return string
     */
    protected function _getScopeCode($scopeType, $scopeCode)
    {
        if (($scopeCode === null || is_numeric($scopeCode))
            && $scopeType !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        ) {
            $scopeResolver = $this->_scopeResolverPool->get($scopeType);
            $scopeCode = $scopeResolver->getScope($scopeCode);
        }

        if ($scopeCode instanceof \Magento\Framework\App\ScopeInterface) {
            $scopeCode = $scopeCode->getCode();
        }

        return $scopeCode;
    }
}

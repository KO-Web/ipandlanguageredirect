<?php
namespace In2code\Ipandlanguageredirect\Domain\Model;

use In2code\Ipandlanguageredirect\Utility\ObjectUtility;

/**
 * Class ConfigurationSet
 */
class ConfigurationSet
{

    /**
     * @var Configuration[]
     */
    protected $configurations = [];

    /**
     * @var array
     */
    protected $rawQuantifierConfiguration = [];

    /**
     * @var array
     */
    protected $rawNoMatchingConfiguration = [];

    /**
     * @var array
     */
    protected $rawRedirectConfiguration = [];

    /**
     * @var int
     */
    protected $partialOrFullMatchOnSameLanguageUid = 0;

    /**
     * @param array $configuration
     */
    public function __construct(array $configuration)
    {
        $this->rawQuantifierConfiguration = $configuration['quantifier'];
        $this->rawNoMatchingConfiguration = $configuration['noMatchingConfiguration'];
        $this->rawRedirectConfiguration = $configuration['redirectConfiguration'];
        foreach ($this->rawRedirectConfiguration as $pageIdentifier => $treeConfiguration) {
            foreach ($treeConfiguration as $languageParameter => $setConfiguration) {
                $configuration = ObjectUtility::getObjectManager()->get(
                    Configuration::class,
                    $pageIdentifier,
                    $languageParameter,
                    $setConfiguration
                );
                $this->addConfiguration($configuration);
            }
        }
        if (isset($this->rawQuantifierConfiguration['browserLanguage']['partialOrFullMatchOnSameLanguageUid'])
            && (int)$this->rawQuantifierConfiguration['browserLanguage']['partialOrFullMatchOnSameLanguageUid'] > 0
        ) {
            $this->partialOrFullMatchOnSameLanguageUid = (int)$this->rawQuantifierConfiguration['browserLanguage']['partialOrFullMatchOnSameLanguageUid'];
        }
    }

    /**
     * @param string $browserLanguage
     * @param string $countryCode
     * @param string $domain
     * @param int $currentLanguage
     * @return void
     */
    public function calculateQuantifiers(string $browserLanguage = '', string $countryCode = '', string $domain = '', $currentLanguage = -2)
    {
        $configurations = $this->getConfigurations();
        foreach ($configurations as $configuration) {
            $browserQuantifier = $this->getLanguageQuantifier(
                $configuration,
                $browserLanguage,
                $currentLanguage
            );
            $regionQuantifier = $this->getQuantifier('countryBasedOnIp', $configuration->getCountries(), $countryCode);
            $domainQuantifier = $this->getQuantifier('domain', $configuration->getDomains(), $domain);
            $configuration->setQuantifier($browserQuantifier * $regionQuantifier * $domainQuantifier);
        }
    }

    /**
     * Calculate a single quantifier by given key
     *
     * @param string $key "browserLanguage", "countryBasedOnIp"
     * @param array $configuration
     * @param string $givenValue - e.g. "*" or "de"
     * @return int
     */
    protected function getQuantifier(string $key, array $configuration, string $givenValue): int
    {
        $quantifier = 1;
        foreach ($configuration as $singleConfiguration) {
            $multiplier = 1;
            if ($singleConfiguration === $givenValue) {
                // direct match
                $multiplier = (int)$this->rawQuantifierConfiguration[$key]['totalMatch'];
            } elseif ($singleConfiguration === '*') {
                // wildcardmatch
                $multiplier = (int)$this->rawQuantifierConfiguration[$key]['wildCardMatch'];
            }
            if ($multiplier > 0) {
                $quantifier *= $multiplier;
            }
        }
        return $quantifier;
    }

    /**
     * Calculate the quantifier and give the currently used language a special treatment if the browserLanguage
     * "partialOrFullMatchOnSameLanguageUid" quantifier is defined. This avoids redirecting a user with "en" as
     * browser language that is visiting the web page corresponding to "en-gb".
     *
     * @param \In2code\Ipandlanguageredirect\Domain\Model\Configuration $configuration Whole configuration.
     * @param string $givenValue - e.g. "*" or "de"
     * @param int $currentLanguage The current language used by the page visitor.
     * @return int
     */
    protected function getLanguageQuantifier(Configuration $configuration, string $givenValue, $currentLanguage = -2): int
    {
        /** @noinspection TypeUnsafeComparisonInspection */
        if ($this->partialOrFullMatchOnSameLanguageUid
            && $configuration->getLanguageParameter() == $currentLanguage
        ) {
            $matches = array_filter(
                $configuration->getBrowserLanguages(),
                function ($browserLanguage) use ($givenValue) {
                    /*
                     * Given language ISO code matches fully or partially only against the configuration of the current
                     * used languageUid.
                     */
                    return strpos($browserLanguage, $givenValue) !== false;
                }
            );

            if (count($matches) > 0) {
                return $this->partialOrFullMatchOnSameLanguageUid;
            }
        }

        return $this->getQuantifier('browserLanguage', $configuration->getBrowserLanguages(), $givenValue);
    }

    /**
     * @return Configuration[]
     */
    public function getConfigurations(): array
    {
        return $this->configurations;
    }

    /**
     * @param Configuration[] $configurations
     * @return ConfigurationSet
     */
    public function setConfigurations(array $configurations): ConfigurationSet
    {
        $this->configurations = $configurations;
        return $this;
    }

    /**
     * @param Configuration $configuration
     * @return ConfigurationSet
     */
    public function addConfiguration(Configuration $configuration): ConfigurationSet
    {
        $this->configurations[] = $configuration;
        return $this;
    }

    /**
     * Return configuration with the highest quantifier
     *
     * @return Configuration|null
     */
    public function getBestFittingConfiguration()
    {
        $bestConfiguration = null;
        foreach ($this->getConfigurations() as $configuration) {
            /** @var Configuration $bestConfiguration */
            if ($bestConfiguration === null || $configuration->getQuantifier() > $bestConfiguration->getQuantifier()) {
                $bestConfiguration = $configuration;
            }
        }
        $this->getBestFittingConfigurationFromNoMatchingConfiguration($bestConfiguration);
        return $bestConfiguration;
    }

    /**
     * Find a configuration by it's identifier
     *
     * @param string $identifier
     * @return Configuration|null
     */
    public function getConfigurationByIdentifier($identifier)
    {
        foreach ($this->getConfigurations() as $configuration) {
            if ($configuration->getIdentifier() === $identifier) {
                return $configuration;
            }
        }
        return null;
    }

    /**
     * If there is no best matching configuration or if the best matching configuration has a too low quantifier
     *
     * @param Configuration|null $bestConfiguration
     * @return Configuration|null
     */
    protected function getBestFittingConfigurationFromNoMatchingConfiguration($bestConfiguration = null)
    {
        if ($bestConfiguration === null ||
            $bestConfiguration->getQuantifier() < $this->rawNoMatchingConfiguration['matchMinQuantifier']) {
            $noMatchingConfiguration = $this->getConfigurationByIdentifier(
                $this->rawNoMatchingConfiguration['identifierUsage']
            );
            if ($noMatchingConfiguration !== null) {
                $bestConfiguration = $noMatchingConfiguration;
            }
        }
        return $bestConfiguration;
    }
}

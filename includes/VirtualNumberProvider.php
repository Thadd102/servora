<?php

require_once __DIR__ . '/FiveSimProvider.php';

class VirtualNumberProvider
{
    private string $provider = "5sim";
    private FiveSimProvider $adapter;

    public function __construct()
    {
        $this->adapter = new FiveSimProvider();
    }

    public function getServices(): array
    {
        return $this->adapter->getServices();
    }

    public function getCountries(string $serviceCode = ''): array
    {
        return $this->adapter->getCountries($serviceCode);
    }

    public function getOptions(string $serviceCode, string $countryCode): array
    {
        return $this->adapter->getOptions($serviceCode, $countryCode);
    }

    public function purchase(string $serviceCode, string $countryCode, string $operatorCode): array
    {
        return $this->adapter->purchase($serviceCode, $countryCode, $operatorCode);
    }

    public function checkStatus(string $providerOrderId): array
    {
        return $this->adapter->checkStatus($providerOrderId);
    }

    public function cancelOrder(string $providerOrderId): array
    {
        return $this->adapter->cancelOrder($providerOrderId);
    }

    public function finishOrder(string $providerOrderId): array
    {
        return $this->adapter->finishOrder($providerOrderId);
    }

    public function getProviderName(): string
    {
        return $this->provider;
    }
}
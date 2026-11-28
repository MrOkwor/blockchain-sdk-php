<?php

namespace BlockchainSdk\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

/** Esplora REST and XRPL JSON endpoints; keeps new drivers usable outside Laravel. */
class NativeHttpClient
{
    private ClientInterface $client;

    public function __construct(private array $config)
    {
        $this->client = $config['http_client'] ?? new Client([
            'timeout' => $config['timeout'] ?? 10,
            'connect_timeout' => $config['connect_timeout'] ?? 3,
            'verify' => $config['verify'] ?? true,
            'allow_redirects' => false,
        ]);
    }

    public function request(string $method, string $path = '', array $options = [], bool $text = false, bool $allowNotFound = false, ?callable $validate = null): mixed
    {
        $last = null;
        foreach ($this->config['rpc_nodes'] ?? [] as $node) {
            try {
                $response = $this->client->request($method, rtrim($node, '/').($path === '' ? '' : '/'.ltrim($path, '/')), $options + ['http_errors' => false]);
                if ($allowNotFound && $response->getStatusCode() === 404) {
                    return [];
                }
                if ($response->getStatusCode() !== 200) {
                    throw new RuntimeException('Provider returned HTTP '.$response->getStatusCode().'.');
                }
                $body = (string) $response->getBody();
                $data = $text ? $body : json_decode($body, true, flags: JSON_THROW_ON_ERROR);
                if (! $text && ! is_array($data)) {
                    throw new RuntimeException('Provider returned invalid JSON data.');
                }

                return $validate ? $validate($data) : $data;
            } catch (\Throwable $error) {
                $last = $error;
            }
        }
        throw new RuntimeException('All configured native-chain providers failed.', previous: $last);
    }
}

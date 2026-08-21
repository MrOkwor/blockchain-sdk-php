<?php

namespace BlockchainSdk\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class RpcClient
{
    private Client $httpClient;
    private array $nodes;
    private int $currentNodeIndex = 0;

    public function __construct(array $nodes, int $timeout = 10, array $headers = [])
    {
        $this->nodes = !empty($nodes) ? array_values($nodes) : ['http://localhost:8545'];
        $this->httpClient = new Client([
            'timeout' => $timeout,
            'verify'  => false,
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
        ]);
    }

    public function call(string $method, array $params = [], int $id = 1): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method'  => $method,
            'params'  => $params,
            'id'      => $id,
        ];

        return $this->executeWithFailover(fn($url) => $this->httpClient->post($url, ['json' => $payload]));
    }

    public function get(string $path = '', array $query = []): array
    {
        return $this->executeWithFailover(fn($url) => $this->httpClient->get(rtrim($url, '/') . '/' . ltrim($path, '/'), ['query' => $query]));
    }

    public function post(string $path = '', array $body = []): array
    {
        return $this->executeWithFailover(fn($url) => $this->httpClient->post(rtrim($url, '/') . '/' . ltrim($path, '/'), ['json' => $body]));
    }

    private function executeWithFailover(callable $callback): array
    {
        $totalNodes = count($this->nodes);
        $attempts = 0;
        $lastException = null;

        while ($attempts < $totalNodes) {
            $url = $this->nodes[$this->currentNodeIndex];
            try {
                $response = $callback($url);
                $body = (string)$response->getBody();
                $data = json_decode($body, true);

                if (isset($data['error'])) {
                    throw new \RuntimeException($data['error']['message'] ?? 'JSON-RPC Error');
                }

                return $data ?? [];
            } catch (\Throwable $e) {
                $lastException = $e;
                $this->currentNodeIndex = ($this->currentNodeIndex + 1) % $totalNodes;
                $attempts++;
            }
        }

        throw new \RuntimeException("All RPC nodes failed. Last error: " . ($lastException?->getMessage() ?? 'Unknown error'));
    }
}
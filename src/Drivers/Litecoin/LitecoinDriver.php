<?php

namespace BlockchainSdk\Drivers\Litecoin;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\Contracts\SweepStateStoreInterface;
use BlockchainSdk\Crypto\Decimal;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionReceipt;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\NativeHttpClient;
use BlockchainSdk\Services\MemorySweepStateStore;
use RuntimeException;

class LitecoinDriver implements NetworkDriverInterface
{
    private SweepStateStoreInterface $state;

    private NativeHttpClient $http;

    private ?array $height = null;

    public function __construct(private array $config)
    {
        $this->state = $config['sweep_state_store'] ?? new MemorySweepStateStore;
        $this->http = new NativeHttpClient($config);
    }

    public function generateWallet(): Keypair
    {
        return (new LitecoinWalletGenerator)->generateWallet();
    }

    public function getCurrency(): string
    {
        return 'LTC';
    }

    public function hasPendingSweep(string $address): bool
    {
        return $this->state->get('litecoin:'.$address) !== null;
    }

    public function completeSweep(string $address): void
    {
        $this->state->forget('litecoin:'.$address);
    }

    public function validateAddress(string $address): bool
    {
        try {
            LitecoinTransactionSigner::addressToScript($address);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getBalance(string $address, ?string $tokenContract = null): TokenBalance
    {
        $this->nativeOnly($tokenContract);
        $stats = $this->request('get', 'address/'.$address)['chain_stats'] ?? [];
        if (! isset($stats['funded_txo_sum'], $stats['spent_txo_sum'])) {
            throw new RuntimeException('Litecoin account balance is unavailable.');
        }
        $raw = bcsub((string) $stats['funded_txo_sum'], (string) $stats['spent_txo_sum'], 0);

        return new TokenBalance('LTC', $raw, Decimal::fromBaseUnit($raw, 8), 8);
    }

    public function getIncomingTransactions(string $address, ?string $tokenContract = null, int $decimals = 18, int $limit = 10): array
    {
        $this->nativeOnly($tokenContract);
        if ($limit <= 0) {
            return [];
        }

        $path = 'address/'.$address.'/txs';
        $result = [];
        $cursor = null;
        $seenCursors = [];
        do {
            $page = $this->request('get', $cursor === null ? $path : $path.'/chain/'.$cursor);
            if (! array_is_list($page)) {
                throw new RuntimeException('Litecoin transaction history is unavailable.');
            }

            foreach ($page as $transaction) {
                foreach ($this->incomingOutputs($transaction, $address) as $output) {
                    $result[] = $output;
                    if (count($result) >= $limit) {
                        return $result;
                    }
                }
            }

            $confirmed = array_filter($page, fn (array $transaction): bool => ($transaction['status']['confirmed'] ?? false) === true);
            if (count($confirmed) < 25) {
                return $result;
            }

            $cursor = end($confirmed)['txid'] ?? '';
            if (! preg_match('/^[0-9a-f]{64}$/iD', $cursor) || isset($seenCursors[$cursor])) {
                throw new RuntimeException('Invalid Litecoin transaction history cursor.');
            }
            $seenCursors[$cursor] = true;
        } while (true);
    }

    public function getIncomingTransactionOutputs(string $address, string $txHash): array
    {
        $transaction = $this->request('get', 'tx/'.$txHash);

        return ($transaction['txid'] ?? null) === $txHash ? $this->incomingOutputs($transaction, $address) : [];
    }

    private function incomingOutputs(array $transaction, string $address): array
    {
        if (in_array($address, array_column(array_column($transaction['vin'] ?? [], 'prevout'), 'scriptpubkey_address'), true)) {
            return [];
        }
        $result = [];
        $block = $transaction['status']['block_height'] ?? null;
        foreach ($transaction['vout'] ?? [] as $index => $output) {
            if (($output['scriptpubkey_address'] ?? null) !== $address || ! preg_match('/^[0-9a-f]{64}$/iD', $transaction['txid'] ?? '')
                || ! preg_match('/^\d+$/D', (string) ($output['value'] ?? '')) || bccomp((string) $output['value'], '0') <= 0) {
                continue;
            }
            $result[] = [
                'tx_hash' => $transaction['txid'], 'log_index' => $index, 'block_number' => $block,
                'from_address' => $transaction['vin'][0]['prevout']['scriptpubkey_address'] ?? null, 'to_address' => $address,
                'amount_raw' => (string) $output['value'], 'amount' => Decimal::fromBaseUnit((string) $output['value'], 8),
                'decimals' => 8, 'confirmations' => $this->confirmations($transaction),
            ];
        }

        return $result;
    }

    private function confirmations(array $transaction): int
    {
        $block = $transaction['status']['block_height'] ?? null;
        if (! ($transaction['status']['confirmed'] ?? false) || $block === null) {
            return 0;
        }

        return max(0, $this->getChainHeight() - (int) $block + 1);
    }

    private function getChainHeight(): int
    {
        if ($this->height === null || microtime(true) > $this->height['at'] + 10) {
            $value = trim((string) $this->request('get', 'blocks/tip/height', text: true));
            if (! ctype_digit($value)) {
                throw new RuntimeException('Litecoin block height is unavailable.');
            }

            $this->height = ['value' => (int) $value, 'at' => microtime(true)];
        }

        return $this->height['value'];
    }

    public function sendTransaction(array $params): TransactionResult
    {
        $this->nativeOnly($params['token_contract'] ?? null);
        $key = $params['from_private_key'] ?? $params['private_key'] ?? '';
        $from = (new LitecoinWalletGenerator)->privateKeyToAddress($key);
        $raw = $params['amount_raw'] ?? $params['amount_sat'] ?? Decimal::toBaseUnit($params['amount'] ?? '0', 8);
        if (! preg_match('/^\d+$/D', (string) $raw) || bccomp((string) $raw, '0') <= 0 || bccomp((string) $raw, (string) PHP_INT_MAX) > 0) {
            throw new RuntimeException('Invalid Litecoin transfer amount.');
        }
        $utxos = $this->utxos($from);
        if ($utxos === []) {
            return new TransactionResult(false, errorMessage: 'No confirmed Litecoin outputs are available.');
        }
        $destinationScript = LitecoinTransactionSigner::addressToScript($params['to']);
        $fee = $this->estimateFee(count($utxos), $destinationScript);
        $total = array_sum(array_column($utxos, 'value'));
        $amount = (int) $raw;
        $change = $total - $amount - $fee;
        if ($change < 0) {
            return new TransactionResult(false, errorMessage: 'Insufficient Litecoin balance including mining fee.');
        }
        $sourceScript = LitecoinTransactionSigner::addressToScript($from);
        $outputs = [['amount' => $amount, 'scriptPubKey' => $destinationScript]];
        if ($change >= 1000) {
            $outputs[] = ['amount' => $change, 'scriptPubKey' => $sourceScript];
        }
        $inputs = array_map(fn (array $utxo): array => [
            'txid' => $utxo['txid'], 'vout' => (int) $utxo['vout'], 'sequence' => 0xFFFFFFFF,
            'amount' => (int) $utxo['value'], 'scriptPubKey' => $sourceScript, 'witness' => [],
        ], $utxos);
        $tx = [
            'version' => 2, 'inputs' => $inputs, 'outputs' => $outputs, 'locktime' => 0,
        ];
        $blob = (new LitecoinTransactionSigner)->signTransaction(['private_key' => $key, 'tx' => $tx]);
        $hash = LitecoinTransactionSigner::transactionId($tx);
        $meta = ['amount' => Decimal::fromBaseUnit((string) $amount, 8), 'retained_balance' => $change >= 1000 ? Decimal::fromBaseUnit((string) $change, 8) : '0'];
        if ($params['sweep'] ?? false) {
            $this->state->put('litecoin:'.$from, ['hash' => $hash, 'blob' => $blob, 'destination' => $params['to'], 'meta' => $meta]);
        }
        $sent = $this->broadcastRawTransaction($blob);
        if (! $sent->success || strtolower($sent->txHash ?? '') !== $hash) {
            throw new RuntimeException('Litecoin provider returned an unexpected transaction hash.');
        }

        return new TransactionResult(true, $hash, meta: $meta);
    }

    public function sweep(string $fromPrivateKey, string $toAddress, ?string $tokenContract = null, ?string $amount = null): TransactionResult
    {
        $address = (new LitecoinWalletGenerator)->privateKeyToAddress($fromPrivateKey);

        return $this->state->synchronized('litecoin:'.$address,
            fn (): TransactionResult => $this->sweepWallet($fromPrivateKey, $toAddress, $tokenContract, $amount));
    }

    private function sweepWallet(string $fromPrivateKey, string $toAddress, ?string $tokenContract, ?string $amount): TransactionResult
    {
        $this->nativeOnly($tokenContract);
        $from = (new LitecoinWalletGenerator)->privateKeyToAddress($fromPrivateKey);
        if ($pending = $this->state->get('litecoin:'.$from)) {
            if ($pending['destination'] !== $toAddress) {
                throw new RuntimeException('A Litecoin sweep to a different vault is still pending.');
            }
            if ($this->request('get', 'tx/'.$pending['hash']) === []) {
                $this->broadcastRawTransaction($pending['blob']);
            }

            return new TransactionResult(true, $pending['hash'], meta: $pending['meta']);
        }
        $utxos = $this->utxos($from);
        $available = array_sum(array_column($utxos, 'value')) - $this->estimateFee(count($utxos), LitecoinTransactionSigner::addressToScript($toAddress));
        if ($available < 1000) {
            return new TransactionResult(false, errorMessage: 'Litecoin balance cannot cover the mining fee and minimum output.');
        }

        if ($amount !== null && (! ctype_digit($amount) || bccomp($amount, '0') <= 0 || bccomp($amount, (string) $available) > 0)) {
            throw new RuntimeException('Invalid Litecoin sweep amount or insufficient spendable outputs.');
        }

        return $this->sendTransaction(['from_private_key' => $fromPrivateKey, 'to' => $toAddress, 'amount_raw' => $amount ?? (string) $available, 'sweep' => true]);
    }

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult
    {
        $hash = trim((string) $this->request('post', 'tx', $signedRawTx, true));
        if (! preg_match('/^[0-9a-f]{64}$/iD', $hash)) {
            throw new RuntimeException('Litecoin broadcast did not return a transaction hash.');
        }

        return new TransactionResult(true, $hash, $signedRawTx);
    }

    public function getTransactionReceipt(string $txHash): ?TransactionReceipt
    {
        $data = $this->request('get', 'tx/'.$txHash);
        if (($data['txid'] ?? '') !== $txHash || ! ($data['status']['confirmed'] ?? false)) {
            return null;
        }

        return new TransactionReceipt($txHash, (int) $data['status']['block_height'], (string) $data['fee'], '1', true, raw: $data, nativeDecimals: 8);
    }

    public function waitForTransactionReceipt(string $txHash, int $timeoutSeconds = 60, int $pollIntervalMs = 2000): TransactionReceipt|array|null
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if ($receipt = $this->getTransactionReceipt($txHash)) {
                return $receipt;
            }
            usleep(max(1, $pollIntervalMs) * 1000);
        } while (microtime(true) < $deadline);

        return null;
    }

    public function getLatestIncomingTxHash(string $address, ?string $tokenContract = null): ?string
    {
        return $this->getIncomingTransactions($address, $tokenContract, limit: 1)[0]['tx_hash'] ?? null;
    }

    public function estimateTokenTransferGasCost(?string $tokenContract = null): string
    {
        $this->nativeOnly($tokenContract);

        return '0';
    }

    public function fuelSubWallet(string $masterGasPrivateKey, string $subWalletAddress, ?string $tokenContract = null): TransactionResult
    {
        return new TransactionResult(false, errorMessage: 'Litecoin pays mining fees from its own outputs.');
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        throw new RuntimeException('Only native LTC is supported.');
    }

    private function nativeOnly(?string $contract): void
    {
        if ($contract !== null) {
            throw new RuntimeException('Only native LTC is supported.');
        }
    }

    private function utxos(string $address): array
    {
        return array_values(array_filter($this->request('get', 'address/'.$address.'/utxo'), fn (array $utxo): bool => ($utxo['status']['confirmed'] ?? false) === true));
    }

    private function estimateFee(int $inputs, string $destinationScript): int
    {
        $rate = $this->request('get', 'v1/fees/recommended')['halfHourFee'] ?? null;
        if (! is_numeric($rate) || $rate <= 0 || $rate > 1000) {
            throw new RuntimeException('A safe Litecoin mining fee is unavailable.');
        }

        return ($inputs * 68 + max(68, (int) (strlen($destinationScript) / 2) + 40) + 11) * (int) ceil($rate);
    }

    private function request(string $method, string $path, ?string $body = null, bool $text = false): mixed
    {
        return $this->http->request(strtoupper($method), $path,
            $method === 'post' ? ['headers' => ['Content-Type' => 'text/plain'], 'body' => $body ?? ''] : [],
            $text, $method === 'get' && str_starts_with($path, 'tx/'));
    }
}

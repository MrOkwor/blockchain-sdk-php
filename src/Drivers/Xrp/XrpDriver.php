<?php

namespace BlockchainSdk\Drivers\Xrp;

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

class XrpDriver implements NetworkDriverInterface
{
    private SweepStateStoreInterface $state;

    private NativeHttpClient $http;

    public function __construct(private array $config)
    {
        $this->state = $config['sweep_state_store'] ?? new MemorySweepStateStore;
        $this->http = new NativeHttpClient($config);
    }

    public function generateWallet(): Keypair
    {
        return (new XrpWalletGenerator)->generateWallet();
    }

    public function getCurrency(): string
    {
        return 'XRP';
    }

    public function hasPendingSweep(string $address, ?string $tokenContract = null): bool
    {
        return $this->state->get($this->stateKey($address, $tokenContract)) !== null;
    }

    public function completeSweep(string $address, ?string $tokenContract = null): void
    {
        $this->state->forget($this->stateKey($address, $tokenContract));
    }

    public function minimumAccountFunding(): string
    {
        $ledger = $this->rpc('server_info')['info']['validated_ledger'] ?? [];
        if (! isset($ledger['reserve_base_xrp']) || ! is_numeric($ledger['reserve_base_xrp']) || $ledger['reserve_base_xrp'] <= 0) {
            throw new RuntimeException('The XRP account activation reserve is unavailable.');
        }

        return (string) $ledger['reserve_base_xrp'];
    }

    public function validateAddress(string $address): bool
    {
        try {
            XrpWalletGenerator::accountId($address);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getBalance(string $address, ?string $tokenContract = null): TokenBalance
    {
        if ($tokenContract !== null) {
            $token = $this->token($tokenContract);
            $line = $this->trustLine($address, $token);
            $value = XrpAmount::plain((string) ($line['balance'] ?? '0'));

            return new TokenBalance($token['symbol'], XrpAmount::raw($value, $token['decimals']), $value, $token['decimals']);
        }
        $account = $this->account($address);
        $raw = (string) ($account['Balance'] ?? '0');

        return new TokenBalance('XRP', $raw, Decimal::fromBaseUnit($raw, 6), 6);
    }

    /** @return array{funded: bool, balance: string, reserve: string, fee: string, spendable: string, ledger: int, sequence: int} */
    public function accountStatus(string $address): array
    {
        $ledger = $this->rpc('server_info')['info']['validated_ledger'] ?? [];
        if (! isset($ledger['reserve_base_xrp'], $ledger['reserve_inc_xrp'], $ledger['seq']) || (int) $ledger['seq'] <= 0) {
            throw new RuntimeException('Validated XRP reserve information is unavailable.');
        }
        $account = $this->account($address, (int) $ledger['seq']);
        $reserve = bcadd(Decimal::toBaseUnit((string) $ledger['reserve_base_xrp'], 6), bcmul((string) ($account['OwnerCount'] ?? 0), Decimal::toBaseUnit((string) $ledger['reserve_inc_xrp'], 6), 0), 0);
        $fee = $this->fee();
        $balance = (string) ($account['Balance'] ?? '0');
        $spendable = bcsub(bcsub($balance, $reserve, 0), $fee, 0);

        return ['funded' => $account !== [], 'balance' => $balance, 'reserve' => $reserve, 'base_reserve' => Decimal::toBaseUnit((string) $ledger['reserve_base_xrp'], 6), 'fee' => $fee,
            'spendable' => bccomp($spendable, '0') > 0 ? $spendable : '0', 'ledger' => (int) $ledger['seq'], 'sequence' => (int) ($account['Sequence'] ?? 0)];
    }

    private function fee(): string
    {
        $fee = (string) ($this->rpc('fee')['drops']['open_ledger_fee'] ?? '');
        if (! ctype_digit($fee) || bccomp($fee, '0') <= 0 || bccomp($fee, (string) ($this->config['max_fee_drops'] ?? 100000)) > 0) {
            throw new RuntimeException('A safe XRP transaction fee is unavailable.');
        }

        return $fee;
    }

    public function getIncomingTransactions(string $address, ?string $tokenContract = null, int $decimals = 18, int $limit = 10): array
    {
        $token = $tokenContract === null ? null : $this->token($tokenContract);
        $transfers = [];
        $marker = null;
        $seenMarkers = [];
        $params = ['account' => $address, 'ledger_index_min' => -1, 'ledger_index_max' => -1, 'binary' => false, 'forward' => false, 'limit' => 200];
        while (count($transfers) < $limit) {
            if ($marker !== null) {
                $params['marker'] = $marker;
            }
            $result = $this->rpc('account_tx', $params, ['actNotFound']);
            if (($result['error'] ?? null) === 'actNotFound') {
                break;
            }
            if (! is_array($result['transactions'] ?? null)) {
                throw new RuntimeException('XRP transaction history is unavailable.');
            }
            foreach ($this->incomingPayments($result['transactions'], $address, $token) as $transfer) {
                $transfers[$transfer['tx_hash']] = $transfer;
                if (count($transfers) >= $limit) {
                    break;
                }
            }

            $marker = $result['marker'] ?? null;
            if ($marker === null) {
                break;
            }
            $markerKey = json_encode($marker, JSON_THROW_ON_ERROR);
            if (isset($seenMarkers[$markerKey])) {
                throw new RuntimeException('Repeated XRP transaction history marker.');
            }
            $seenMarkers[$markerKey] = true;
        }

        return array_values($transfers);
    }

    private function incomingPayments(array $transactions, string $address, ?array $token): array
    {
        $transfers = [];
        foreach ($transactions as $entry) {
            $tx = $entry['tx'] ?? $entry['tx_json'] ?? [];
            $meta = $entry['meta'] ?? [];
            $raw = $meta['delivered_amount'] ?? null;
            $hash = $entry['hash'] ?? $tx['hash'] ?? '';
            if (($entry['validated'] ?? false) !== true || ($meta['TransactionResult'] ?? null) !== 'tesSUCCESS'
                || ($tx['TransactionType'] ?? null) !== 'Payment' || ($tx['Destination'] ?? null) !== $address
                || ($tx['Account'] ?? null) === $address
                || ! preg_match('/^[0-9a-f]{64}$/iD', $hash)) {
                continue;
            }
            if ($token !== null) {
                if (! is_array($raw) || ($raw['issuer'] ?? '') !== $token['issuer'] || ! $this->sameCurrency($raw['currency'] ?? '', $token['currency'])) {
                    continue;
                }
                $raw = XrpAmount::raw((string) ($raw['value'] ?? ''), $token['decimals']);
            } elseif (! is_string($raw) || ! ctype_digit($raw)) {
                continue;
            }
            if (bccomp($raw, '0') <= 0) {
                continue;
            }
            $scale = $token['decimals'] ?? 6;
            $transfers[] = ['tx_hash' => strtoupper($hash), 'log_index' => 0, 'block_number' => (int) ($tx['ledger_index'] ?? $entry['ledger_index'] ?? 0),
                'from_address' => $tx['Account'], 'to_address' => $address, 'amount_raw' => $raw,
                'amount' => Decimal::fromBaseUnit($raw, $scale), 'decimals' => $scale, 'confirmations' => 1,
                'destination_tag' => $tx['DestinationTag'] ?? null];
        }

        return $transfers;
    }

    public function sendTransaction(array $params): TransactionResult
    {
        $key = $params['from_private_key'] ?? $params['private_key'] ?? '';
        $from = (new XrpWalletGenerator)->privateKeyToAddress($key);
        $status = $this->accountStatus($from);

        if (isset($params['token_contract'])) {
            $token = $this->token($params['token_contract']);
            $value = isset($params['amount_raw']) ? Decimal::fromBaseUnit($params['amount_raw'], $token['decimals']) : (string) ($params['amount'] ?? '0');
            $prepared = $this->prepareTokenPayment($key, $params['to'], $value, $token, $status, $params['destination_tag'] ?? null);

            return $this->broadcastRawTransaction($prepared->rawSignedHex);
        }

        return $this->sendPayment($key, $params['to'], (string) ($params['amount_raw'] ?? XrpAmount::raw((string) ($params['amount'] ?? '0'), 6)), $status, $params['destination_tag'] ?? null);
    }

    public function sweep(string $fromPrivateKey, string $toAddress, ?string $tokenContract = null, ?string $amount = null): TransactionResult
    {
        $address = (new XrpWalletGenerator)->privateKeyToAddress($fromPrivateKey);

        return $this->state->synchronized('xrp:'.$address,
            fn (): TransactionResult => $this->sweepWallet($fromPrivateKey, $toAddress, $tokenContract, $amount));
    }

    private function sweepWallet(string $fromPrivateKey, string $toAddress, ?string $tokenContract, ?string $amount): TransactionResult
    {
        $from = (new XrpWalletGenerator)->privateKeyToAddress($fromPrivateKey);
        $cacheKey = $this->stateKey($from, $tokenContract);
        if ($pending = $this->state->get($cacheKey)) {
            if ($pending['destination'] !== $toAddress || ($pending['token_contract'] ?? null) !== $tokenContract) {
                throw new RuntimeException('Another XRP sweep is pending for this account; confirm it before sending the next asset.');
            }
            $receipt = $this->getTransactionReceipt($pending['hash']);
            if ($receipt && ! $receipt->isSuccessful) {
                $this->state->forget($cacheKey);

                return new TransactionResult(false, errorMessage: 'The previous XRP sweep failed in a validated ledger.');
            }
            if ($receipt) {
                return new TransactionResult(true, $pending['hash'], meta: $pending['meta']);
            }
            $ledger = (int) ($this->rpc('server_info')['info']['validated_ledger']['seq'] ?? 0);
            if ($ledger <= 0) {
                throw new RuntimeException('Validated XRP ledger index is unavailable.');
            }
            if ($ledger <= $pending['last_ledger']) {
                return $this->submitSweep($pending['blob'], $pending['meta']);
            }
            $this->state->forget($cacheKey);
        }
        $status = $this->accountStatus($from);
        if ($tokenContract !== null) {
            $token = $this->token($tokenContract);
            $balance = $this->getBalance($from, $tokenContract);
            $budgetRaw = $amount ?? $balance->balanceRaw;
            if (! ctype_digit($budgetRaw) || bccomp($budgetRaw, '0') <= 0 || bccomp($budgetRaw, $balance->balanceRaw) > 0) {
                throw new RuntimeException('Invalid XRP token sweep amount.');
            }
            $issuer = $this->account($token['issuer']);
            $rate = $this->transferRate($issuer, $from, $toAddress, $token['issuer']);
            $deliverRaw = bcdiv(bcmul($budgetRaw, '1000000000', 0), $rate, 0);
            $value = Decimal::fromBaseUnit($deliverRaw, $token['decimals']);
            $result = $this->prepareTokenPayment($fromPrivateKey, $toAddress, $value, $token, $status, $this->config['sweep_destination_tag'] ?? null);
            $meta = ['amount' => $value, 'token_contract' => $token['contract'], 'retained_balance' => Decimal::fromBaseUnit(bcsub($status['balance'], $status['fee'], 0), 6)];
        } else {
            if ($status['spendable'] === '0') {
                return new TransactionResult(true, meta: ['nothing_to_sweep' => true, 'retained_balance' => Decimal::fromBaseUnit($status['balance'], 6)]);
            }
            if ($amount !== null && (! ctype_digit($amount) || bccomp($amount, '0') <= 0 || bccomp($amount, $status['spendable']) > 0)) {
                throw new RuntimeException('Invalid XRP sweep amount or insufficient spendable XRP.');
            }
            $raw = $amount ?? $status['spendable'];
            $result = $this->preparePayment($fromPrivateKey, $toAddress, $raw, $status, $this->config['sweep_destination_tag'] ?? null);
            $meta = ['amount' => Decimal::fromBaseUnit($raw, 6), 'retained_balance' => Decimal::fromBaseUnit(bcsub(bcsub($status['balance'], $raw, 0), $status['fee'], 0), 6)];
        }
        $meta['source_ledger'] = $status['ledger'];
        $this->state->put($cacheKey, ['hash' => $result->txHash, 'blob' => $result->rawSignedHex, 'destination' => $toAddress, 'token_contract' => $tokenContract, 'last_ledger' => $status['ledger'] + 20, 'meta' => $meta]);

        return $this->submitSweep($result->rawSignedHex, $meta);
    }

    private function submitSweep(string $blob, array $meta): TransactionResult
    {
        $sent = $this->broadcastRawTransaction($blob);

        return new TransactionResult($sent->success, $sent->txHash, errorMessage: $sent->errorMessage, meta: $meta);
    }

    private function sendPayment(string $key, string $to, string $raw, array $status, ?int $tag = null): TransactionResult
    {
        $prepared = $this->preparePayment($key, $to, $raw, $status, $tag);

        return $this->broadcastRawTransaction($prepared->rawSignedHex);
    }

    private function preparePayment(string $key, string $to, string $raw, array $status, ?int $tag = null): TransactionResult
    {
        if (! $this->validateAddress($to) || ! ctype_digit($raw) || bccomp($raw, '0') <= 0 || bccomp($raw, $status['spendable']) > 0 || ! $status['funded']) {
            throw new RuntimeException('Invalid XRP destination or insufficient spendable balance after reserve and fee.');
        }
        $destination = $this->account($to);
        if ($destination === [] && bccomp($raw, $status['base_reserve']) < 0) {
            throw new RuntimeException('The XRP payment is too small to activate the destination account.');
        }
        if (((int) ($destination['Flags'] ?? 0) & 0x00020000) !== 0 && $tag === null) {
            throw new RuntimeException('The XRP destination requires a destination tag.');
        }
        $tx = ['TransactionType' => 'Payment', 'Flags' => 0x80000000, 'Account' => (new XrpWalletGenerator)->privateKeyToAddress($key),
            'Destination' => $to, 'Amount' => $raw, 'Fee' => $status['fee'], 'Sequence' => $status['sequence'], 'LastLedgerSequence' => $status['ledger'] + 20];
        if ($tag !== null) {
            $tx['DestinationTag'] = $tag;
        }
        $blob = (new XrpTransactionSigner)->signTransaction(['private_key' => $key, 'tx' => $tx]);

        return new TransactionResult(true, XrpTransactionSigner::transactionHash($blob), $blob);
    }

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult
    {
        $hash = XrpTransactionSigner::transactionHash($signedRawTx);
        $result = $this->rpc('submit', ['tx_blob' => $signedRawTx, 'fail_hard' => true]);
        if (! in_array($result['engine_result'] ?? null, ['tesSUCCESS', 'terQUEUED', 'tefALREADY'], true)) {
            return new TransactionResult(false, errorMessage: 'XRP submission rejected: '.($result['engine_result'] ?? 'unknown'));
        }

        return new TransactionResult(true, $hash, $signedRawTx);
    }

    public function getTransactionReceipt(string $txHash): ?TransactionReceipt
    {
        $result = $this->rpc('tx', ['transaction' => $txHash, 'binary' => false], ['txnNotFound']);
        if (($result['error'] ?? null) === 'txnNotFound' || ($result['validated'] ?? false) !== true) {
            return null;
        }
        $meta = $result['meta'] ?? [];
        $tx = $result['tx_json'] ?? $result;

        return new TransactionReceipt($txHash, (int) ($result['ledger_index'] ?? 0), (string) ($tx['Fee'] ?? '0'), '1',
            ($meta['TransactionResult'] ?? null) === 'tesSUCCESS', raw: $result, nativeDecimals: 6);
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
        if ($tokenContract !== null) {
            $this->token($tokenContract);
        }

        return $this->fee();
    }

    public function fuelSubWallet(string $masterGasPrivateKey, string $subWalletAddress, ?string $tokenContract = null): TransactionResult
    {
        $status = $this->accountStatus($subWalletAddress);
        $needed = bcsub(bcadd($status['reserve'], bcmul($status['fee'], '2', 0), 0), $status['balance'], 0);
        if (bccomp($needed, '0') <= 0) {
            return new TransactionResult(true, meta: ['nothing_to_fund' => true]);
        }

        return $this->sendTransaction(['from_private_key' => $masterGasPrivateKey, 'to' => $subWalletAddress, 'amount_raw' => $needed]);
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        $from = (new XrpWalletGenerator)->privateKeyToAddress($subWalletPrivateKey);
        if ($this->hasPendingSweep($from)) {
            return $this->sweep($subWalletPrivateKey, $toVaultAddress, $tokenContract, $amount);
        }
        $fuel = $this->fuelSubWallet($masterGasPrivateKey, $from, $tokenContract);
        if (! $fuel->success || ($fuel->txHash !== null && ! $this->waitForTransactionReceipt($fuel->txHash)?->isSuccessful)) {
            return new TransactionResult(false, errorMessage: 'XRP fee funding is not yet confirmed; retry after validation.');
        }

        return $this->sweep($subWalletPrivateKey, $toVaultAddress, $tokenContract, $amount);
    }

    /** Explicit opt-in: accepting a token requires a funded account and an on-ledger trust line. */
    public function establishTrustLine(string $privateKey, string $tokenContract, string $limit): TransactionResult
    {
        $token = $this->token($tokenContract);
        $limit = XrpAmount::plain($limit);
        XrpAmount::raw($limit, $token['decimals']);
        if (bccomp($limit, '0', 8) <= 0) {
            throw new RuntimeException('A positive trust-line limit is required.');
        }
        $from = (new XrpWalletGenerator)->privateKeyToAddress($privateKey);
        $status = $this->accountStatus($from);
        $ledger = $this->rpc('server_info')['info']['validated_ledger'];
        $additionalReserve = $this->trustLine($from, $token) === [] ? Decimal::toBaseUnit((string) $ledger['reserve_inc_xrp'], 6) : '0';
        if (! $status['funded'] || bccomp(bcsub(bcsub($status['balance'], $status['reserve'], 0), $status['fee'], 0), $additionalReserve) < 0) {
            throw new RuntimeException('Fund the XRP account reserve, trust-line reserve and fee before enrollment.');
        }
        $tx = ['TransactionType' => 'TrustSet', 'Flags' => 0x80020000, 'Account' => $from,
            'LimitAmount' => ['currency' => $token['currency'], 'issuer' => $token['issuer'], 'value' => $limit],
            'Fee' => $status['fee'], 'Sequence' => $status['sequence'], 'LastLedgerSequence' => $status['ledger'] + 20];

        return $this->broadcastRawTransaction((new XrpTransactionSigner)->signTransaction(['private_key' => $privateKey, 'tx' => $tx]));
    }

    /** @return array{currency: string, issuer: string, contract: string, symbol: string, decimals: int} */
    private function token(string $identifier): array
    {
        foreach ($this->config['tokens'] ?? [] as $symbol => $token) {
            $contract = $token['contract'] ?? (($token['currency'] ?? '').':'.($token['issuer'] ?? ''));
            if ($identifier !== $contract && $identifier !== (string) $symbol) {
                continue;
            }
            if (! in_array($token['status'] ?? 'enabled', ['enabled', true], true) || isset($token['mpt_issuance_id'])) {
                throw new RuntimeException('This XRP token is disabled or uses an unsupported token standard.');
            }
            [$currency, $issuer] = array_pad(explode(':', $contract, 2), 2, '');
            XrpAmount::currencyHex($currency);
            XrpWalletGenerator::accountId($issuer);
            $decimals = $token['decimals'] ?? null;
            if (! is_int($decimals) || $decimals < 0 || $decimals > 8) {
                throw new RuntimeException('Configure an XRP token accounting scale from 0 to 8 decimals.');
            }

            return ['currency' => $currency, 'issuer' => $issuer, 'contract' => $contract, 'symbol' => (string) $symbol, 'decimals' => $decimals];
        }
        throw new RuntimeException('Configure the XRP token by currency and issuer before use.');
    }

    private function sameCurrency(string $a, string $b): bool
    {
        return strtoupper(XrpAmount::currencyHex($a)) === strtoupper(XrpAmount::currencyHex($b));
    }

    private function trustLine(string $address, array $token): array
    {
        $marker = null;
        do {
            $params = ['account' => $address, 'peer' => $token['issuer'], 'ledger_index' => 'validated', 'limit' => 400];
            if ($marker !== null) {
                $params['marker'] = $marker;
            }
            $result = $this->rpc('account_lines', $params, ['actNotFound']);
            if (($result['error'] ?? null) === 'actNotFound') {
                return [];
            }
            if (($result['validated'] ?? false) !== true || ! is_array($result['lines'] ?? null)) {
                throw new RuntimeException('Validated XRP trust-line data is unavailable.');
            }
            foreach ($result['lines'] as $line) {
                if (($line['account'] ?? '') === $token['issuer'] && $this->sameCurrency($line['currency'] ?? '', $token['currency'])) {
                    return $line;
                }
            }
            $marker = $result['marker'] ?? null;
        } while ($marker !== null);

        return [];
    }

    private function transferRate(array $issuer, string $from, string $to, string $issuerAddress): string
    {
        $rate = $from === $issuerAddress || $to === $issuerAddress ? '1000000000' : (string) ($issuer['TransferRate'] ?? '1000000000');
        if (! ctype_digit($rate) || bccomp($rate, '1000000000') < 0 || bccomp($rate, '2000000000') > 0) {
            throw new RuntimeException('Unsupported XRP issuer transfer rate.');
        }

        return $rate;
    }

    private function prepareTokenPayment(string $key, string $to, string $value, array $token, array $status, ?int $tag = null): TransactionResult
    {
        $from = (new XrpWalletGenerator)->privateKeyToAddress($key);
        $value = XrpAmount::plain($value);
        XrpAmount::raw($value, $token['decimals']);
        if (! $this->validateAddress($to) || $from === $to || ! $status['funded'] || bccomp($value, '0', 8) <= 0
            || bccomp(bcsub($status['balance'], $status['reserve'], 0), $status['fee']) < 0) {
            throw new RuntimeException('Invalid XRP token payment or insufficient XRP reserve/fee.');
        }
        $destination = $this->account($to);
        if ($destination === [] || (((int) ($destination['Flags'] ?? 0) & 0x00020000) !== 0 && $tag === null)) {
            throw new RuntimeException('The XRP token destination must be funded and its required destination tag supplied.');
        }
        $issuer = $this->account($token['issuer']);
        if ($issuer === [] || ((int) ($issuer['Flags'] ?? 0) & 0x00400000) !== 0) {
            throw new RuntimeException('The XRP issuer is unavailable or has globally frozen this token.');
        }
        $rate = $this->transferRate($issuer, $from, $to, $token['issuer']);
        $cost = bcdiv(bcmul($value, $rate, 16), '1000000000', 16);
        $sourceLine = $from === $token['issuer'] ? [] : $this->trustLine($from, $token);
        $destLine = $to === $token['issuer'] ? [] : $this->trustLine($to, $token);
        foreach ([$from => $sourceLine, $to => $destLine] as $account => $line) {
            if ($account === $token['issuer']) {
                continue;
            }
            if ($line === [] || ($line['freeze_peer'] ?? false) || ($line['deep_freeze_peer'] ?? false)
                || (((int) ($issuer['Flags'] ?? 0) & 0x00040000) !== 0 && ! ($line['peer_authorized'] ?? false))) {
                throw new RuntimeException('XRP trust line is missing, frozen or unauthorized.');
            }
        }
        if ($from !== $token['issuer'] && bccomp(XrpAmount::plain((string) $sourceLine['balance']), $cost, 16) < 0) {
            throw new RuntimeException('Insufficient XRP token balance including issuer transfer fee.');
        }
        if ($to !== $token['issuer'] && bccomp(bcadd(XrpAmount::plain((string) $destLine['balance']), $value, 16), XrpAmount::plain((string) $destLine['limit']), 16) > 0) {
            throw new RuntimeException('The XRP destination trust-line limit is insufficient.');
        }
        $asset = ['currency' => $token['currency'], 'issuer' => $token['issuer']];
        $tx = ['TransactionType' => 'Payment', 'Flags' => 0x80000000, 'Account' => $from, 'Destination' => $to,
            'Amount' => $asset + ['value' => $value], 'SendMax' => $asset + ['value' => rtrim(rtrim($cost, '0'), '.')],
            'Fee' => $status['fee'], 'Sequence' => $status['sequence'], 'LastLedgerSequence' => $status['ledger'] + 20];
        if ($tag !== null) {
            $tx['DestinationTag'] = $tag;
        }
        $blob = (new XrpTransactionSigner)->signTransaction(['private_key' => $key, 'tx' => $tx]);

        return new TransactionResult(true, XrpTransactionSigner::transactionHash($blob), $blob);
    }

    private function stateKey(string $address, ?string $tokenContract): string
    {
        return 'xrp:'.$address;
    }

    private function account(string $address, int|string $ledger = 'validated'): array
    {
        $result = $this->rpc('account_info', ['account' => $address, 'ledger_index' => $ledger, 'strict' => true], ['actNotFound']);
        if (($result['error'] ?? null) === 'actNotFound') {
            return [];
        }
        if (($result['validated'] ?? false) !== true || ! isset($result['account_data']['Balance'], $result['account_data']['Sequence'])) {
            throw new RuntimeException('Validated XRP account information is unavailable.');
        }

        return $result['account_data'];
    }

    private function rpc(string $method, array $params = [], array $allowedErrors = []): array
    {
        return $this->http->request('POST', options: ['json' => ['method' => $method, 'params' => [$params === [] ? (object) [] : $params]]], validate: function (array $data) use ($method, $allowedErrors): array {
            $result = $data['result'] ?? null;
            if (! is_array($result) || (isset($result['error']) && ! in_array($result['error'], $allowedErrors, true))) {
                throw new RuntimeException('Invalid XRP '.$method.' response.');
            }

            return $result;
        });
    }
}

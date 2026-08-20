<?php

namespace BlockchainSdk\Drivers\Evm;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\RpcClient;

class EvmDriver implements NetworkDriverInterface
{
    private EvmWalletGenerator $generator;
    private EvmTransactionSigner $signer;
    private RpcClient $rpc;
    private int $chainId;
    private string $currency;

    public function __construct(array $config)
    {
        $this->generator = new EvmWalletGenerator();
        $this->signer = new EvmTransactionSigner();
        $this->rpc = new RpcClient($config['rpc_nodes'] ?? ['https://cloudflare-eth.com']);
        $this->chainId = (int)($config['chain_id'] ?? 1);
        $this->currency = $config['currency'] ?? 'ETH';
    }

    public function generateWallet(): Keypair
    {
        return $this->generator->generateWallet();
    }

    public function validateAddress(string $address): bool
    {
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return false;
        }

        // If purely lowercase or purely uppercase, valid format
        $sub = substr($address, 2);
        if (strtolower($sub) === $sub || strtoupper($sub) === $sub) {
            return true;
        }

        // If mixed-case, verify EIP-55 checksum
        return EvmWalletGenerator::toChecksumAddress($address) === $address;
    }

    public function getBalance(string $address, ?string $tokenContract = null): TokenBalance
    {
        if ($tokenContract) {
            $data = '0x70a08231000000000000000000000000' . ltrim($address, '0x');
            $res = $this->rpc->call('eth_call', [['to' => $tokenContract, 'data' => $data], 'latest']);
            $hex = $res['result'] ?? '0x0';
            $rawDec = gmp_strval(gmp_init($hex, 16), 10);
            return new TokenBalance('TOKEN', $rawDec, bcdiv($rawDec, '1000000', 6), 6);
        }

        $res = $this->rpc->call('eth_getBalance', [$address, 'latest']);
        $hex = $res['result'] ?? '0x0';
        $wei = gmp_strval(gmp_init($hex, 16), 10);
        return new TokenBalance($this->currency, $wei, bcdiv($wei, '1000000000000000000', 6), 18);
    }

    public function sendTransaction(array $params): TransactionResult
    {
        $fromPrivateKey = $params['from_private_key'] ?? $params['private_key'] ?? '';
        $from = $this->generator->privateKeyToAddress($fromPrivateKey);
        $params['from_private_key'] = $fromPrivateKey;
        $params['private_key'] = $fromPrivateKey;
        
        $nonceRes = $this->rpc->call('eth_getTransactionCount', [$from, 'pending']);
        $nonce = hexdec($nonceRes['result'] ?? '0x0');

        $gasPriceRes = $this->rpc->call('eth_gasPrice', []);
        $gasPriceWei = gmp_strval(gmp_init($gasPriceRes['result'] ?? '0x4a817c800', 16), 10);

        $params['nonce'] = $params['nonce'] ?? $nonce;
        $params['gas_price'] = $params['gas_price'] ?? $gasPriceWei;

        $tokenContract = $params['token_contract'] ?? null;
        if ($tokenContract && empty($params['data'])) {
            $recipient = $params['to'];
            $decimals = (int)($params['decimals'] ?? 18);
            $amountRaw = (string)($params['amount_raw'] ?? bcmul((string)($params['amount'] ?? '0'), bcpow('10', (string)$decimals), 0));
            $params['to'] = $tokenContract;
            $params['data'] = EvmTransactionSigner::buildErc20TransferData($recipient, $amountRaw);
            $params['value'] = '0';
            $params['gas_limit'] = $params['gas_limit'] ?? 65000;
        } elseif (isset($params['amount']) && !isset($params['value'])) {
            $params['value'] = bcmul((string)$params['amount'], '1000000000000000000', 0);
            $params['gas_limit'] = $params['gas_limit'] ?? 21000;
        } else {
            $params['gas_limit'] = $params['gas_limit'] ?? (!empty($params['data']) ? 65000 : 21000);
        }

        $params['chain_id'] = $this->chainId;

        $signedRaw = $this->signer->signTransaction($params);
        return $this->broadcastRawTransaction($signedRaw);
    }

    public function sweep(string $fromPrivateKey, string $toAddress, ?string $tokenContract = null, ?string $amount = null): TransactionResult
    {
        $from = $this->generator->privateKeyToAddress($fromPrivateKey);
        $balance = $this->getBalance($from, $tokenContract);

        if ($tokenContract) {
            $sweepAmount = $amount ?? $balance->balanceRaw;
            if (bccomp($sweepAmount, '0') <= 0) {
                return new TransactionResult(false, null, null, "No token balance found to sweep on {$from}.");
            }

            $data = EvmTransactionSigner::buildErc20TransferData($toAddress, $sweepAmount);
            return $this->sendTransaction([
                'from_private_key' => $fromPrivateKey,
                'to' => $tokenContract,
                'value' => '0',
                'data' => $data,
                'gas_limit' => 65000,
            ]);
        }

        $gasPriceRes = $this->rpc->call('eth_gasPrice', []);
        $gasPriceWei = gmp_strval(gmp_init($gasPriceRes['result'] ?? '0x4a817c800', 16), 10);
        $totalGasFee = bcmul($gasPriceWei, '21000');

        $sweepable = bcsub($balance->balanceRaw, $totalGasFee);
        if (bccomp($sweepable, '0') <= 0) {
            return new TransactionResult(false, null, null, "Insufficient native balance to cover transaction gas fee.");
        }

        return $this->sendTransaction([
            'from_private_key' => $fromPrivateKey,
            'to' => $toAddress,
            'value' => $sweepable,
            'gas_limit' => 21000,
        ]);
    }

    public function estimateTokenTransferGasCost(?string $tokenContract = null): string
    {
        try {
            $gasPriceRes = $this->rpc->call('eth_gasPrice', []);
            $gasPriceWei = gmp_strval(gmp_init($gasPriceRes['result'] ?? '0x4a817c800', 16), 10);
            return bcmul($gasPriceWei, '65000'); // Standard ERC-20 transfer limit
        } catch (\Throwable $e) {
            return '1500000000000000'; // Default ~0.0015 ETH/BNB
        }
    }

    public function fuelSubWallet(string $masterGasPrivateKey, string $subWalletAddress, ?string $tokenContract = null): TransactionResult
    {
        $requiredGasWei = $this->estimateTokenTransferGasCost($tokenContract);
        $currentBalanceWei = $this->getBalance($subWalletAddress)->balanceRaw;

        // If sub-wallet already has enough gas, no fueling needed
        if (bccomp($currentBalanceWei, $requiredGasWei) >= 0) {
            return new TransactionResult(true, null, null, "Sub-wallet already has sufficient gas.");
        }

        $deficitWei = bcsub($requiredGasWei, $currentBalanceWei);
        // Add 10% safety buffer for gas price fluctuations
        $fuelAmountWei = bcadd($deficitWei, bcdiv($deficitWei, '10', 0));

        return $this->sendTransaction([
            'from_private_key' => $masterGasPrivateKey,
            'to'               => $subWalletAddress,
            'value'            => $fuelAmountWei,
            'gas_limit'        => 21000,
        ]);
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        $fromAddress = $this->generator->privateKeyToAddress($subWalletPrivateKey);
        
        // 1. Ensure sub-wallet is fueled with native gas
        $fuelResult = $this->fuelSubWallet($masterGasPrivateKey, $fromAddress, $tokenContract);
        if (!$fuelResult->success && empty($fuelResult->txHash)) {
            return new TransactionResult(false, null, null, "Failed to fuel sub-wallet with gas: " . ($fuelResult->errorMessage ?? 'Unknown error'));
        }

        // 2. Execute ERC-20 token sweep
        return $this->sweep($subWalletPrivateKey, $toVaultAddress, $tokenContract, $amount);
    }

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult
    {
        try {
            $res = $this->rpc->call('eth_sendRawTransaction', [$signedRawTx]);
            $txHash = $res['result'] ?? null;
            return new TransactionResult(
                success: !empty($txHash),
                txHash: $txHash,
                rawSignedHex: $signedRawTx,
                errorMessage: $res['error']['message'] ?? null
            );
        } catch (\Throwable $e) {
            return new TransactionResult(false, null, $signedRawTx, $e->getMessage());
        }
    }
}
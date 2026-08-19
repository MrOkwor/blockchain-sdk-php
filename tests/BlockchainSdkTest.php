<?php

namespace BlockchainSdk\Tests;

use BlockchainSdk\BlockchainManager;
use BlockchainSdk\Drivers\Bitcoin\BitcoinTransactionSigner;
use BlockchainSdk\Drivers\Evm\EvmTransactionSigner;
use BlockchainSdk\Drivers\Solana\SolanaTransactionSigner;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class BlockchainSdkTest extends TestCase
{
    private BlockchainManager $sdk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sdk = new BlockchainManager([
            'default' => 'ethereum',
            'master_wallets' => [
                'ethereum' => '0x71C8360f3a104d31a4570b9A821929342939b422',
                'bsc'      => '0x55d398326f99059fF775485246999027B3197955',
            ],
            'master_gas_keys' => [
                'ethereum' => '0x4f3edf983ac636a65a842ce7c78d9aa706d3b113bce9c46f30d7d21715b23b1d', // Plaintext
            ],
            'networks' => [
                'ethereum' => [
                    'chain_id' => 1,
                    'currency' => 'ETH',
                    'rpc_nodes' => ['https://cloudflare-eth.com'],
                    'tokens' => [
                        'USDT' => ['name' => 'Tether USD', 'contract' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'decimals' => 6, 'status' => 'enabled'],
                        'OLD_TOKEN' => ['name' => 'Old Token', 'contract' => '0x0000000000000000000000000000000000000001', 'decimals' => 18, 'status' => 'disabled'],
                    ]
                ],
                'bsc'      => ['chain_id' => 56, 'currency' => 'BNB', 'rpc_nodes' => ['https://bsc-dataseed.binance.org']],
                'solana'   => ['rpc_nodes' => ['https://api.mainnet-beta.solana.com']],
                'bitcoin'  => ['rpc_nodes' => ['https://mempool.space/api']],
                'tron'     => ['rpc_nodes' => ['https://api.trongrid.io']],
            ]
        ]);
    }

    public function test_ethereum_wallet_generation_and_signing(): void
    {
        $wallet = $this->sdk->driver('ethereum')->generateWallet();
        $this->assertStringStartsWith('0x', $wallet->address);
        $this->assertEquals(42, strlen($wallet->address));

        // Test EIP-155 transaction signing
        $signedRaw = (new EvmTransactionSigner())->signTransaction([
            'private_key' => $wallet->privateKey,
            'to'          => '0x0000000000000000000000000000000000000000',
            'nonce'       => 0,
            'gas_price'   => '20000000000',
            'gas_limit'   => 21000,
            'value'       => '1000000000000000000',
            'chain_id'    => 1,
        ]);

        $this->assertStringStartsWith('0x', $signedRaw);
        $this->assertGreaterThan(100, strlen($signedRaw));
    }

    public function test_solana_wallet_generation_and_signing(): void
    {
        $wallet = $this->sdk->driver('solana')->generateWallet();
        $this->assertGreaterThanOrEqual(32, strlen($wallet->address));
        $this->assertLessThanOrEqual(44, strlen($wallet->address));

        // Test Native SOL transaction signing
        $dummyBlockhash = '4uQeVj5tqViQh7yWWGStvkEG1Zmhx6uasJtWCJziofM';
        $signedTx = (new SolanaTransactionSigner())->signTransaction([
            'private_key'      => $wallet->privateKey,
            'from_address'     => $wallet->address,
            'to_address'       => '11111111111111111111111111111111',
            'recent_blockhash' => $dummyBlockhash,
            'lamports'         => 100000000,
        ]);

        $this->assertNotEmpty($signedTx);
        $this->assertIsString($signedTx);
    }

    public function test_bitcoin_bech32_generation_and_bip143_signing(): void
    {
        $wallet = $this->sdk->driver('bitcoin')->generateWallet();
        $this->assertStringStartsWith('bc1q', $wallet->address);

        // Test BIP-143 transaction assembly and signing
        $dummyUtxos = [
            [
                'txid'  => '4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b',
                'vout'  => 0,
                'value' => 1000000,
            ]
        ];

        $unsigned = BitcoinTransactionSigner::buildUnsignedSegwitTx(
            utxos: $dummyUtxos,
            toAddress: 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
            amountSat: 500000,
            changeAddress: $wallet->address,
            feeRateSatVb: 10
        );

        $this->assertNotNull($unsigned);
        $this->assertCount(1, $unsigned['inputs']);

        $signedHex = (new BitcoinTransactionSigner())->signTransaction([
            'tx'          => $unsigned,
            'private_key' => $wallet->privateKey,
        ]);

        $this->assertNotEmpty($signedHex);
        $this->assertStringStartsWith('010000000001', $signedHex);
    }

    public function test_tron_wallet_generation(): void
    {
        $wallet = $this->sdk->driver('tron')->generateWallet();
        $this->assertStringStartsWith('T', $wallet->address);
        $this->assertEquals(34, strlen($wallet->address));
    }

    public function test_token_enable_and_status_filtering(): void
    {
        $allTokens = $this->sdk->getSupportedTokens('ethereum', onlyEnabled: false);
        $this->assertCount(2, $allTokens);

        $enabledTokens = $this->sdk->getSupportedTokens('ethereum', onlyEnabled: true);
        $this->assertCount(1, $enabledTokens);
        $this->assertArrayHasKey('USDT', $enabledTokens);
        $this->assertArrayNotHasKey('OLD_TOKEN', $enabledTokens);

        $this->assertTrue($this->sdk->isTokenEnabled('ethereum', 'USDT'));
        $this->assertFalse($this->sdk->isTokenEnabled('ethereum', 'OLD_TOKEN'));
    }

    public function test_gas_cost_estimation_across_drivers(): void
    {
        $evmGas = $this->sdk->driver('ethereum')->estimateTokenTransferGasCost();
        $this->assertNotEmpty($evmGas);
        $this->assertGreaterThan(0, (float)$evmGas);

        $solGas = $this->sdk->driver('solana')->estimateTokenTransferGasCost();
        $this->assertEquals('5000', $solGas);

        $tronGas = $this->sdk->driver('tron')->estimateTokenTransferGasCost();
        $this->assertEquals('15000000', $tronGas);
    }

    public function test_address_validation_across_chains(): void
    {
        $this->assertTrue($this->sdk->validateAddress('ethereum', '0xdAC17F958D2ee523a2206206994597C13D831ec7'));
        $this->assertTrue($this->sdk->validateAddress('bsc', '0x55d398326f99059ff775485246999027b3197955'));
        $this->assertFalse($this->sdk->validateAddress('ethereum', '0xInvalidAddress123'));
        $this->assertFalse($this->sdk->validateAddress('ethereum', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));

        $this->assertTrue($this->sdk->validateAddress('solana', 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v'));
        $this->assertFalse($this->sdk->validateAddress('solana', '0xdAC17F958D2ee523a2206206994597C13D831ec7'));

        $this->assertTrue($this->sdk->validateAddress('tron', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'));
        $this->assertFalse($this->sdk->validateAddress('tron', '0xdAC17F958D2ee523a2206206994597C13D831ec7'));

        $this->assertTrue($this->sdk->validateAddress('bitcoin', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'));
        $this->assertTrue($this->sdk->validateAddress('bitcoin', '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa'));
        $this->assertFalse($this->sdk->validateAddress('bitcoin', 'InvalidBtcAddress'));
    }

    public function test_safe_secret_decryption_and_master_wallet_lookup(): void
    {
        // 1. Plaintext secret decryption
        $plain = '0x1234567890abcdef1234567890abcdef1234567890abcdef1234567890abcdef';
        $this->assertEquals($plain, BlockchainManager::decryptSecret($plain));

        // 2. Encrypted secret decryption (using Laravel Crypt)
        $encrypted = Crypt::encryptString($plain);
        $this->assertNotEquals($plain, $encrypted);
        $this->assertEquals($plain, BlockchainManager::decryptSecret($encrypted));

        // 3. Manager master gas key & master wallet lookups
        $this->assertEquals('0x71C8360f3a104d31a4570b9A821929342939b422', $this->sdk->getMasterWallet('ethereum'));
        $this->assertEquals('0x4f3edf983ac636a65a842ce7c78d9aa706d3b113bce9c46f30d7d21715b23b1d', $this->sdk->getMasterGasKey('ethereum'));
    }
}
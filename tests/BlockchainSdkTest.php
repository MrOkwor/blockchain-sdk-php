<?php

namespace BlockchainSdk\Tests;

use BlockchainSdk\BlockchainManager;
use BlockchainSdk\Crypto\Decimal;
use BlockchainSdk\Crypto\Keccak;
use BlockchainSdk\Crypto\Secp256k1;
use BlockchainSdk\Drivers\Bitcoin\BitcoinTransactionSigner;
use BlockchainSdk\Drivers\Evm\EvmDriver;
use BlockchainSdk\Drivers\Evm\EvmTransactionSigner;
use BlockchainSdk\Drivers\Solana\SolanaTransactionSigner;
use BlockchainSdk\Http\RpcClient;
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
            'master_gas_wallets' => [
                'ethereum' => [
                    'address'     => '0x71C8360f3a104d31a4570b9A821929342939b422',
                    'private_key' => 'plain:0x4f3edf983ac636a65a842ce7c78d9aa706d3b113bce9c46f30d7d21715b23b1d',
                ],
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

    public function test_rfc6979_deterministic_signing_vector(): void
    {
        // Known private key and message hash
        $privKey = 'c8524633ec5d64f622a8069fdef9b731f21099fd34942ab83326d8b2ab5f0221';
        $messageHash = '0000000000000000000000000000000000000000000000000000000000000001';

        $sig1 = Secp256k1::signRfc6979($privKey, $messageHash);
        $sig2 = Secp256k1::signRfc6979($privKey, $messageHash);

        // Deterministic RFC 6979 must produce identical r and s across runs
        $this->assertEquals($sig1['r'], $sig2['r']);
        $this->assertEquals($sig1['s'], $sig2['s']);
        $this->assertContains($sig1['v'], [0, 1]);
        $this->assertNotEmpty($sig1['r']);
        $this->assertNotEmpty($sig1['s']);
    }

    public function test_strip0x_helper_preserves_leading_zeroes(): void
    {
        // Must strip 0x without removing meaningful 00 bytes (CRYPTO-02)
        $this->assertEquals('001234567890abcdef', EvmTransactionSigner::strip0x('0x001234567890abcdef'));
        $this->assertEquals('0000000000000000', EvmTransactionSigner::strip0x('0x0000000000000000'));
        $this->assertEquals('abcdef', EvmTransactionSigner::strip0x('abcdef'));
    }

    public function test_erc20_transfer_calldata_assembly(): void
    {
        $to = '0x71C8360f3a104d31a4570b9A821929342939b422';
        $amountWei = '50000000000000000000'; // 50 tokens (18 dec)

        $calldata = EvmTransactionSigner::buildErc20TransferData($to, $amountWei);

        // a9059cbb + 32-byte padded address + 32-byte padded amount = 136 chars
        $this->assertStringStartsWith('a9059cbb', $calldata);
        $this->assertEquals(136, strlen($calldata));
        $this->assertStringContainsString('71c8360f3a104d31a4570b9a821929342939b422', $calldata);
    }

    public function test_decimal_conversion_accuracy(): void
    {
        // 6 Decimals (USDT/USDC)
        $raw6 = Decimal::toBaseUnit('50.123456', 6);
        $this->assertEquals('50123456', $raw6);
        $formatted6 = Decimal::fromBaseUnit($raw6, 6, 6);
        $this->assertEquals('50.123456', $formatted6);

        // 18 Decimals (ETH/BNB)
        $raw18 = Decimal::toBaseUnit('1.5', 18);
        $this->assertEquals('1500000000000000000', $raw18);
        $formatted18 = Decimal::fromBaseUnit($raw18, 18, 2);
        $this->assertEquals('1.50', $formatted18);
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

    public function test_secret_modes_and_fail_closed_decryption(): void
    {
        $rawKey = '0x4f3edf983ac636a65a842ce7c78d9aa706d3b113bce9c46f30d7d21715b23b1d';

        // 1. Explicit plaintext mode (plain:)
        $this->assertEquals($rawKey, BlockchainManager::decryptSecret("plain:{$rawKey}"));

        // 2. Explicit encrypted mode (enc:v1:)
        $cipher = Crypt::encryptString($rawKey);
        $this->assertEquals($rawKey, BlockchainManager::decryptSecret("enc:v1:{$cipher}"));

        // 3. Corrupted enc:v1: must fail closed with RuntimeException (SEC-02)
        $this->expectException(\RuntimeException::class);
        BlockchainManager::decryptSecret("enc:v1:CorruptedInvalidPayload");
    }

    public function test_unconfigured_unresolvable_token_decimals_fails_explicitly(): void
    {
        $driver = $this->sdk->driver('ethereum');

        // Non-existent token contract with no RPC response must fail explicitly (ACC-04b)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine decimals for token contract');
        $driver->getBalance('0x71C8360f3a104d31a4570b9A821929342939b422', '0x1111111111111111111111111111111111111111');
    }
}
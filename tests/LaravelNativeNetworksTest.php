<?php

namespace BlockchainSdk\Tests;

use BlockchainSdk\BlockchainManager;
use BlockchainSdk\Crypto\MasterWalletDerivation;
use BlockchainSdk\Drivers\Litecoin\LitecoinDriver;
use BlockchainSdk\Drivers\Xrp\XrpDriver;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionReceipt;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Laravel\Commands\GenerateMasterWalletsCommand;
use BlockchainSdk\Laravel\Commands\SweepCommand;
use BlockchainSdk\Laravel\Events\WalletSwept;
use BlockchainSdk\Laravel\Facades\Blockchain;
use BlockchainSdk\Laravel\Models\BlockchainSdkDeposit;
use BlockchainSdk\Laravel\Models\BlockchainSdkSweep;
use BlockchainSdk\Laravel\Models\BlockchainSdkWallet;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/** Laravel integration tests run in a host Laravel application's PHPUnit harness. */
class LaravelNativeNetworksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['blockchainsdk.models.wallet' => BlockchainSdkWallet::class,
            'blockchainsdk.models.deposit' => BlockchainSdkDeposit::class,
            'blockchainsdk.models.sweep' => BlockchainSdkSweep::class,
            'blockchainsdk.networks.litecoin.type' => 'litecoin']);
        foreach (['wallets', 'deposits', 'sweeps'] as $name) {
            (require __DIR__.'/../database/migrations/create_blockchainsdk_'.$name.'_table.php.stub')->up();
        }
    }

    private function wallet(): BlockchainSdkWallet
    {
        return BlockchainSdkWallet::create(['network' => 'litecoin', 'address' => 'wallet', 'private_key' => 'test-key', 'balance' => '2', 'is_active' => true]);
    }

    public function test_published_configuration_registers_both_native_networks_and_master_wallet_settings(): void
    {
        $config = require __DIR__.'/../config/blockchainsdk.php';
        $manager = new BlockchainManager($config);
        $this->assertContains('litecoin', $manager->getAvailableNetworks());
        $this->assertContains('xrp', $manager->getAvailableNetworks());
        $this->assertArrayHasKey('address', $config['master_gas_wallets']['litecoin']);
        $this->assertArrayHasKey('private_key', $config['master_gas_wallets']['xrp']);
    }

    public function test_seed_mode_preserves_legacy_wallets_and_uses_one_encrypted_phrase_for_new_networks(): void
    {
        $envPath = tempnam(sys_get_temp_dir(), 'blockchainsdk-test-');
        $originalPath = $this->app->environmentPath();
        $originalFile = $this->app->environmentFile();
        $this->app->useEnvironmentPath(dirname($envPath))->loadEnvironmentFrom(basename($envPath));
        file_put_contents($envPath, implode("\n", [
            'BLOCKCHAIN_MASTER_ETHEREUM="legacy-vault"',
            'BLOCKCHAIN_GAS_ADDRESS_ETHEREUM="legacy-gas"',
            'BLOCKCHAIN_GAS_KEY_ETHEREUM="old-private-key"',
            'BLOCKCHAIN_MASTER_LITECOIN="custom-vault"',
        ])."\n");

        try {
            $sdk = new BlockchainManager(['networks' => [
                'ethereum' => ['type' => 'evm'],
                'litecoin' => ['type' => 'litecoin'],
                'xrp' => ['type' => 'xrp'],
            ]]);
            Blockchain::swap($sdk);
            $command = new GenerateMasterWalletsCommand;
            $command->setLaravel($this->app);
            $tester = new CommandTester($command);

            $this->assertSame(0, $tester->execute(['--seed-phrase' => true, 'network' => 'ethereum']));
            $this->assertArrayNotHasKey('BLOCKCHAIN_MASTER_SEED_PHRASE', Dotenv::parse(file_get_contents($envPath)));

            $this->assertSame(0, $tester->execute(['--seed-phrase' => true, 'network' => 'litecoin']));
            $first = Dotenv::parse(file_get_contents($envPath));
            $this->assertSame('custom-vault', $first['BLOCKCHAIN_MASTER_LITECOIN']);
            $this->assertSame('old-private-key', $first['BLOCKCHAIN_GAS_KEY_ETHEREUM']);
            $this->assertSame('legacy-vault', $first['BLOCKCHAIN_MASTER_ETHEREUM']);
            $this->assertSame('legacy-gas', $first['BLOCKCHAIN_GAS_ADDRESS_ETHEREUM']);
            $this->assertStringStartsWith('enc:v1:', $first['BLOCKCHAIN_MASTER_SEED_PHRASE']);

            $mnemonic = Crypt::decryptString(substr($first['BLOCKCHAIN_MASTER_SEED_PHRASE'], 7));
            $this->assertStringNotContainsString($mnemonic, $tester->getDisplay());
            $this->assertStringNotContainsString($mnemonic, file_get_contents($envPath));
            $derivation = new MasterWalletDerivation;
            $seed = $derivation->seedFromMnemonic($mnemonic);
            $derived = $derivation->deriveWallet($seed, $sdk->driver('litecoin'));
            $this->assertSame($derived->privateKey, BlockchainManager::decryptSecret($first['BLOCKCHAIN_GAS_KEY_LITECOIN']));
            $this->assertSame($derived->address, $first['BLOCKCHAIN_GAS_ADDRESS_LITECOIN']);

            $this->assertSame(0, $tester->execute(['--seed-phrase' => true, 'network' => 'xrp']));
            $content = file_get_contents($envPath);
            $second = Dotenv::parse($content);
            $this->assertSame($first['BLOCKCHAIN_MASTER_SEED_PHRASE'], $second['BLOCKCHAIN_MASTER_SEED_PHRASE']);
            $this->assertSame($second['BLOCKCHAIN_MASTER_XRP'], $second['BLOCKCHAIN_GAS_ADDRESS_XRP']);
            $this->assertSame($derivation->deriveWallet($seed, $sdk->driver('xrp'))->address, $second['BLOCKCHAIN_GAS_ADDRESS_XRP']);

            $this->assertSame(0, $tester->execute(['--seed-phrase' => true, '--show-seed-phrase' => true, 'network' => 'xrp']));
            $this->assertStringContainsString($mnemonic, $tester->getDisplay());
            $this->assertSame($content, file_get_contents($envPath));

            $this->assertSame(0, $tester->execute(['--seed-phrase' => true, '--no-store' => true, 'network' => 'ethereum']));
            $this->assertStringContainsString($mnemonic, $tester->getDisplay());
            $this->assertSame($content, file_get_contents($envPath));

            $this->assertSame(0, $tester->execute(['--seed-phrase' => true, '--force' => true, 'network' => 'ethereum']));
            $rotated = Dotenv::parse(file_get_contents($envPath));
            $evm = $derivation->deriveWallet($seed, $sdk->driver('ethereum'));
            $this->assertSame('legacy-vault', $rotated['BLOCKCHAIN_MASTER_ETHEREUM']);
            $this->assertSame($evm->address, $rotated['BLOCKCHAIN_GAS_ADDRESS_ETHEREUM']);
            $this->assertSame($evm->privateKey, BlockchainManager::decryptSecret($rotated['BLOCKCHAIN_GAS_KEY_ETHEREUM']));

            $corrupt = str_replace($rotated['BLOCKCHAIN_MASTER_SEED_PHRASE'], 'enc:v1:invalid', file_get_contents($envPath));
            file_put_contents($envPath, $corrupt);
            $this->assertSame(1, $tester->execute(['--seed-phrase' => true, '--force' => true, 'network' => 'ethereum']));
            $this->assertSame($corrupt, file_get_contents($envPath));
        } finally {
            $this->app->useEnvironmentPath($originalPath)->loadEnvironmentFrom($originalFile);
            unlink($envPath);
        }
    }

    public function test_wallet_modes_insert_entries_under_existing_headers_and_preserve_crlf(): void
    {
        $envPath = tempnam(sys_get_temp_dir(), 'blockchainsdk-test-');
        $originalPath = $this->app->environmentPath();
        $originalFile = $this->app->environmentFile();
        $this->app->useEnvironmentPath(dirname($envPath))->loadEnvironmentFrom(basename($envPath));

        try {
            Blockchain::swap(new BlockchainManager(['networks' => [
                'ethereum' => ['type' => 'evm'],
                'litecoin' => ['type' => 'litecoin'],
                'xrp' => ['type' => 'xrp'],
            ]]));
            $command = new GenerateMasterWalletsCommand;
            $command->setLaravel($this->app);
            $tester = new CommandTester($command);
            $divider = '# ==============================================================================';
            $masterHeader = '# BLOCKCHAIN SDK: MASTER COLD VAULT RECEIVING WALLETS';
            $gasHeader = '# BLOCKCHAIN SDK: HOT GAS STATION WALLETS & PRIVATE KEYS';
            $seedHeader = '# BLOCKCHAIN SDK: WALLET RECOVERY PHRASE';

            file_put_contents($envPath, implode("\r\n", [
                'ENV_BEFORE="keep"',
                $divider, $masterHeader, '# Vault addresses', $divider,
                'BLOCKCHAIN_MASTER_ETHEREUM="existing-master"',
                '',
                $divider, $gasHeader, '# Gas wallets', $divider,
                'BLOCKCHAIN_GAS_ADDRESS_ETHEREUM="existing-gas-address"',
                'BLOCKCHAIN_GAS_KEY_ETHEREUM="existing-unprefixed-key"',
                '',
                'ENV_AFTER="keep"',
                '',
            ]));

            $this->assertSame(0, $tester->execute(['network' => 'ethereum']));
            $legacy = Dotenv::parse(file_get_contents($envPath));
            $this->assertSame('existing-gas-address', $legacy['BLOCKCHAIN_GAS_ADDRESS_ETHEREUM']);
            $this->assertSame('plain:existing-unprefixed-key', $legacy['BLOCKCHAIN_GAS_KEY_ETHEREUM']);

            $this->assertSame(0, $tester->execute(['network' => 'litecoin', '--seed-phrase' => true]));
            $this->assertSame(0, $tester->execute(['network' => 'xrp']));
            $env = file_get_contents($envPath);
            $master = strstr($env, $masterHeader);
            $gas = strstr($master, $gasHeader);
            $seed = strstr($gas, $seedHeader);
            $master = substr($master, 0, strpos($master, $gasHeader));
            $gas = substr($gas, 0, strpos($gas, 'ENV_AFTER='));

            foreach ([$masterHeader, $gasHeader, $seedHeader] as $header) {
                $this->assertSame(1, substr_count($env, $header));
            }
            foreach (['LITECOIN', 'XRP'] as $network) {
                $this->assertStringContainsString("BLOCKCHAIN_MASTER_{$network}=", $master);
                $this->assertStringContainsString("BLOCKCHAIN_GAS_ADDRESS_{$network}=", $gas);
                $this->assertStringContainsString("BLOCKCHAIN_GAS_KEY_{$network}=", $gas);
            }
            $this->assertStringContainsString('BLOCKCHAIN_MASTER_SEED_PHRASE="enc:v1:', $seed);
            $this->assertStringNotContainsString("\n", str_replace("\r\n", '', $env));
            $this->assertStringContainsString('ENV_AFTER="keep"', $env);

            $this->assertSame(0, $tester->execute(['network' => 'litecoin', '--seed-phrase' => true]));
            $this->assertSame($env, file_get_contents($envPath));
        } finally {
            $this->app->useEnvironmentPath($originalPath)->loadEnvironmentFrom($originalFile);
            unlink($envPath);
        }
    }

    private function command(LitecoinDriver|XrpDriver $driver): CommandTester
    {
        $manager = $this->createMock(BlockchainManager::class);
        $manager->method('driver')->willReturn($driver);
        $manager->method('getMasterWallet')->willReturn('vault');
        Blockchain::swap($manager);
        $command = new SweepCommand;
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }

    public function test_unconfirmed_sweep_does_not_credit_or_change_wallet_balance(): void
    {
        Event::fake([WalletSwept::class]);
        $wallet = $this->wallet();
        $driver = $this->createMock(LitecoinDriver::class);
        $driver->method('getBalance')->willReturn(new TokenBalance('LTC', '200000000', '2.00000000', 8));
        $driver->method('sweep')->willReturn(new TransactionResult(true, str_repeat('a', 64), meta: ['amount' => '1.99999']));
        $driver->method('waitForTransactionReceipt')->willReturn(null);
        $driver->expects($this->never())->method('completeSweep');

        $this->assertSame(0, $this->command($driver)->execute(['network' => 'litecoin', '--credit' => true]));
        $this->assertSame(0, BlockchainSdkSweep::count());
        $this->assertEquals(2, $wallet->fresh()->balance);
        Event::assertNotDispatched(WalletSwept::class);
    }

    public function test_confirmed_pending_sweep_is_recorded_once_even_after_wallet_balance_is_zero(): void
    {
        Event::fake([WalletSwept::class]);
        $wallet = $this->wallet();
        $driver = $this->createMock(LitecoinDriver::class);
        $driver->method('getBalance')->willReturn(new TokenBalance('LTC', '0', '0.00000000', 8));
        $driver->method('hasPendingSweep')->willReturn(true);
        $driver->method('sweep')->willReturn(new TransactionResult(true, str_repeat('a', 64), meta: ['amount' => '1.99999', 'retained_balance' => '0']));
        $driver->method('waitForTransactionReceipt')->willReturn(new TransactionReceipt(str_repeat('a', 64), 100, '1000', '1', true, nativeDecimals: 8));
        $driver->expects($this->exactly(2))->method('completeSweep');
        $command = $this->command($driver);

        $this->assertSame(0, $command->execute(['network' => 'litecoin', '--credit' => true]));
        $this->assertSame(0, $command->execute(['network' => 'litecoin', '--credit' => true]));
        $this->assertSame(1, BlockchainSdkSweep::count());
        $this->assertSame('1.99999000', BlockchainSdkSweep::first()->amount);
        $this->assertSame('0.00001000', BlockchainSdkSweep::first()->fee_spent);
        $this->assertEquals(0, $wallet->fresh()->balance);
        Event::assertDispatchedTimes(WalletSwept::class, 1);
    }

    public function test_litecoin_only_marks_confirmed_deposits_whose_outputs_were_spent(): void
    {
        Event::fake([WalletSwept::class]);
        $wallet = $this->wallet();
        $spent = $this->deposit($wallet, 'b', 0);
        $otherOutput = $this->deposit($wallet, 'b', 1);
        $pending = $this->deposit($wallet, 'c', 0, status: 'pending');
        $hash = str_repeat('a', 64);
        $driver = $this->createMock(LitecoinDriver::class);
        $driver->method('getBalance')->willReturn(new TokenBalance('LTC', '200000000', '2', 8));
        $driver->method('sweep')->willReturn(new TransactionResult(true, $hash, meta: ['amount' => '1']));
        $driver->method('waitForTransactionReceipt')->willReturn(new TransactionReceipt(
            $hash, 100, '1000', '1', true,
            raw: ['vin' => [['txid' => $spent->tx_hash, 'vout' => 0], ['txid' => $pending->tx_hash, 'vout' => 0]]],
            nativeDecimals: 8
        ));

        $this->assertSame(0, $this->command($driver)->execute(['network' => 'litecoin']));
        $this->assertTrue($spent->fresh()->is_swept);
        $this->assertFalse($otherOutput->fresh()->is_swept);
        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_xrp_only_marks_whole_deposits_covered_at_the_source_ledger(): void
    {
        Event::fake([WalletSwept::class]);
        config(['blockchainsdk.networks.xrp.type' => 'xrp']);
        $wallet = $this->wallet();
        $wallet->update(['network' => 'xrp']);
        $covered = $this->deposit($wallet, 'b', 0, block: 98);
        $uncovered = $this->deposit($wallet, 'c', 0, block: 99);
        $newer = $this->deposit($wallet, 'd', 0, block: 101);
        $hash = str_repeat('a', 64);
        $driver = $this->createMock(XrpDriver::class);
        $driver->method('getBalance')->willReturn(new TokenBalance('XRP', '3000000', '3', 6));
        $driver->method('sweep')->willReturn(new TransactionResult(true, $hash, meta: ['amount' => '1.5', 'source_ledger' => 100]));
        $driver->method('waitForTransactionReceipt')->willReturn(new TransactionReceipt($hash, 102, '10', '1', true, nativeDecimals: 6));

        $this->assertSame(0, $this->command($driver)->execute(['network' => 'xrp']));
        $this->assertTrue($covered->fresh()->is_swept);
        $this->assertFalse($uncovered->fresh()->is_swept);
        $this->assertFalse($newer->fresh()->is_swept);
    }

    private function deposit(BlockchainSdkWallet $wallet, string $hashCharacter, int $output, string $status = 'confirmed', int $block = 99): BlockchainSdkDeposit
    {
        return BlockchainSdkDeposit::create([
            'wallet_id' => $wallet->id,
            'network' => $wallet->network,
            'tx_hash' => str_repeat($hashCharacter, 64),
            'log_index' => $output,
            'to_address' => $wallet->address,
            'amount' => '1',
            'status' => $status,
            'block_number' => $block,
        ]);
    }

    public function test_failed_validated_receipt_is_not_treated_as_completed(): void
    {
        Event::fake([WalletSwept::class]);
        $this->wallet();
        $driver = $this->createMock(LitecoinDriver::class);
        $driver->method('getBalance')->willReturn(new TokenBalance('LTC', '200000000', '2.00000000', 8));
        $driver->method('sweep')->willReturn(new TransactionResult(true, str_repeat('a', 64)));
        $driver->method('waitForTransactionReceipt')->willReturn(new TransactionReceipt(str_repeat('a', 64), 100, '1000', '1', false, nativeDecimals: 8));
        $this->command($driver)->execute(['network' => 'litecoin', '--credit' => true]);

        $this->assertSame(0, BlockchainSdkSweep::count());
        Event::assertNotDispatched(WalletSwept::class);
    }

    public function test_recording_failure_rolls_back_the_sweep_and_keeps_it_retryable(): void
    {
        Event::fake([WalletSwept::class]);
        $wallet = $this->wallet();
        $driver = $this->createMock(LitecoinDriver::class);
        $driver->method('getBalance')->willReturn(new TokenBalance('LTC', '200000000', '2.00000000', 8));
        $driver->method('sweep')->willReturn(new TransactionResult(true, str_repeat('a', 64), meta: ['amount' => '1.99999', 'retained_balance' => '0']));
        $driver->method('waitForTransactionReceipt')->willReturn(new TransactionReceipt(str_repeat('a', 64), 100, '1000', '1', true, nativeDecimals: 8));
        $driver->expects($this->never())->method('completeSweep');
        BlockchainSdkWallet::updating(function (): void {
            throw new \RuntimeException('Simulated persistence failure.');
        });
        try {
            $this->command($driver)->execute(['network' => 'litecoin', '--credit' => true]);
            $this->assertSame(0, BlockchainSdkSweep::count());
            $this->assertEquals(2, $wallet->fresh()->balance);
            Event::assertNotDispatched(WalletSwept::class);
        } finally {
            BlockchainSdkWallet::flushEventListeners();
        }
    }
}

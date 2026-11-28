<?php

namespace BlockchainSdk\Laravel\Commands;

use App\Models\BlockchainSdkDeposit;
use App\Models\BlockchainSdkSweep;
use App\Models\BlockchainSdkWallet;
use BlockchainSdk\DTOs\TransactionReceipt;
use BlockchainSdk\Laravel\Events\WalletSwept;
use BlockchainSdk\Laravel\Facades\Blockchain;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SweepCommand extends Command
{
    protected $signature = 'blockchainsdk:sweep 
                            {network? : Target blockchain network (ethereum, bsc, polygon, arbitrum, solana, bitcoin, tron, etc.)} 
                            {--network= : Target blockchain network} 
                            {--token= : Optional token symbol or contract address} 
                            {--sponsor : Automatically sponsor/fuel native gas from master gas station if sub-wallet is dry}
                            {--credit : Automatically record sweep in database and dispatch WalletSwept event to give value to user}
                            {--all : Sweep all tokens first, then sweep native balance last}';

    protected $description = 'Sweep customer sub-wallet balances into central cold/master vaults';

    public function handle(): int
    {
        $network = strtolower($this->argument('network') ?? $this->option('network') ?? config('blockchainsdk.default', 'ethereum'));
        $tokenInput = $this->option('token');
        $shouldSponsor = (bool) $this->option('sponsor');
        $shouldCredit = (bool) $this->option('credit');

        $walletModel = config('blockchainsdk.models.wallet', BlockchainSdkWallet::class);
        $sweepModel = config('blockchainsdk.models.sweep', BlockchainSdkSweep::class);
        $depositModel = config('blockchainsdk.models.deposit', BlockchainSdkDeposit::class);

        if (! class_exists($walletModel) || ! class_exists($sweepModel)) {
            $this->error("BlockchainSdk models not found. Run 'php artisan vendor:publish --tag=blockchainsdk-models'");

            return self::FAILURE;
        }

        if ($this->option('all')) {
            $this->info("Executing ordered token-first sweep for network [{$network}]...");

            // Phase 1: Sweep all enabled tokens first
            $tokens = Blockchain::getSupportedTokens($network, true);
            foreach ($tokens as $sym => $token) {
                $tokenSymbol = is_string($sym) ? $sym : ($token['symbol'] ?? 'TOKEN');
                $this->line("Sweeping token [{$tokenSymbol}]...");
                $this->call('blockchainsdk:sweep', array_filter([
                    'network' => $network,
                    '--token' => $tokenSymbol,
                    '--sponsor' => $shouldSponsor,
                    '--credit' => $shouldCredit,
                ]));
            }

            // Phase 2: Sweep native currency last
            $this->line("Sweeping native currency for [{$network}]...");

            return $this->call('blockchainsdk:sweep', array_filter([
                'network' => $network,
                '--credit' => $shouldCredit,
            ]));
        }

        $masterVault = Blockchain::getMasterWallet($network);
        if (! $masterVault) {
            $this->error("No master vault wallet configured for network [{$network}]. Set BLOCKCHAIN_MASTER_".strtoupper($network)." in .env or run: php artisan blockchainsdk:generate-master-wallets {$network}");

            return self::FAILURE;
        }

        $gasKey = $shouldSponsor ? Blockchain::getMasterGasKey($network) : null;
        if ($shouldSponsor && ! $gasKey) {
            $this->warn('Automated gas sponsorship requested (--sponsor), but no BLOCKCHAIN_GAS_KEY_'.strtoupper($network).' is configured.');
        }

        $driver = Blockchain::driver($network);
        $networkType = config('blockchainsdk.networks.'.$network.'.type', $network);
        $requiresFinality = in_array($networkType, ['xrp', 'litecoin'], true);
        $tokenContract = null;
        $tokenSymbol = 'NATIVE';

        if ($tokenInput) {
            $tokenInfo = Blockchain::findToken($network, $tokenInput, false);
            if ($tokenInfo) {
                $status = $tokenInfo['status'] ?? 'enabled';
                if ($status !== 'enabled' && $status !== true) {
                    $this->error("Token [{$tokenInfo['symbol']}] is currently disabled for sweeping on network [{$network}].");

                    return self::FAILURE;
                }

                $tokenContract = $tokenInfo['contract'] ?? $tokenInput;
                $tokenSymbol = $tokenInfo['symbol'] ?? 'TOKEN';
                $this->line("Target Token: <fg=yellow>{$tokenInfo['name']} ({$tokenSymbol})</> - Status: <fg=green>Enabled</> - Contract: {$tokenContract}");
            } else {
                $tokenContract = $tokenInput;
                $tokenSymbol = 'TOKEN';
                $this->line("Target Contract: <fg=yellow>{$tokenContract}</>");
            }
        } else {
            $this->line('Target Asset: <fg=green>Native Currency</>');
        }

        $this->line("Destination Master Vault: <fg=cyan>{$masterVault}</>");

        $wallets = $walletModel::where('network', $network)
            ->where('is_active', true)
            ->get();

        if ($wallets->isEmpty()) {
            $this->line("No active wallets found for network [{$network}].");

            return self::SUCCESS;
        }

        $this->line("Scanning {$wallets->count()} active sub-wallets on [{$network}]...");

        $sweptCount = 0;

        foreach ($wallets as $wallet) {
            try {
                $balance = $driver->getBalance($wallet->address, $tokenContract);
                $balanceAmount = $requiresFinality ? $balance->balanceFormatted : (float) $balance->balanceFormatted;

                $empty = $requiresFinality ? bccomp((string) $balanceAmount, '0', $balance->decimals) <= 0 : $balanceAmount <= 0;
                if ($empty && ! ($requiresFinality && $driver->hasPendingSweep($wallet->address, $tokenContract))) {
                    continue;
                }

                $this->info("Found balance: {$balanceAmount} {$tokenSymbol} on [{$wallet->address}]. Sweeping...");

                if ($tokenContract && $shouldSponsor && $gasKey) {
                    $result = $driver->sweepTokenWithGasSponsorship(
                        subWalletPrivateKey: $wallet->private_key,
                        masterGasPrivateKey: $gasKey,
                        toVaultAddress: $masterVault,
                        tokenContract: $tokenContract
                    );
                } else {
                    $result = $driver->sweep(
                        fromPrivateKey: $wallet->private_key,
                        toAddress: $masterVault,
                        tokenContract: $tokenContract
                    );
                }

                if ($result->success && ! empty($result->txHash)) {
                    $this->info("✓ Sweep broadcasted! TxHash: {$result->txHash}");

                    $confirmedFee = '0';
                    $receipt = null;
                    // Confirm on-chain receipt if supported
                    try {
                        $receipt = $driver->waitForTransactionReceipt($result->txHash);
                        if ($receipt) {
                            $blockNum = is_numeric($receipt->blockNumber ?? null) ? $receipt->blockNumber : hexdec($receipt['blockNumber'] ?? '0x0');
                            $this->info("✓ Sweep confirmed on-chain in block #{$blockNum}");
                            $confirmedFee = $receipt->feeSpent() ?? '0';
                        }
                    } catch (\Throwable $e) {
                        $this->warn('Sweep receipt check notice: '.$e->getMessage());
                    }

                    if ($requiresFinality && (! $receipt instanceof TransactionReceipt || ! $receipt->isSuccessful)) {
                        $this->warn('Sweep has not succeeded in a confirmed block/validated ledger; no deposit or balance changes were applied.');

                        continue;
                    }

                    $values = [
                        'wallet_id' => $wallet->id,
                        'network' => $network,
                        'from_address' => $wallet->address,
                        'to_vault_address' => $masterVault,
                        'token_symbol' => $tokenSymbol,
                        'token_contract' => $tokenContract,
                        'amount' => $requiresFinality ? ($result->meta['amount'] ?? $balanceAmount) : $balanceAmount,
                        'tx_hash' => $result->txHash,
                        'fee_spent' => $confirmedFee,
                        'status' => 'completed',
                        'is_credited' => $shouldCredit,
                        'credited_at' => $shouldCredit ? now() : null,
                    ];
                    $recordSweep = function () use ($requiresFinality, $sweepModel, $result, $values, $depositModel, $wallet, $tokenContract, $networkType, $receipt): Model {
                        $sweepRecord = $requiresFinality ? $sweepModel::firstOrCreate(['tx_hash' => $result->txHash], $values) : $sweepModel::create($values);
                        if ($requiresFinality && ! $sweepRecord->wasRecentlyCreated) {
                            return $sweepRecord;
                        }
                        if (class_exists($depositModel)) {
                            $deposits = $depositModel::where('wallet_id', $wallet->id)->where('is_swept', false);
                            $updates = [
                                'is_swept' => true,
                                'sweep_tx_hash' => $result->txHash,
                                'status' => 'swept',
                                'swept_at' => now(),
                            ];
                            if ($requiresFinality) {
                                $deposits->where('token_contract', $tokenContract)->whereIn('status', ['confirmed', 'credited']);
                                $this->markCoveredDeposits($deposits, $networkType, $receipt, $result->meta, $updates);
                            } else {
                                $deposits->update($updates);
                            }
                        }

                        $wallet->update(['balance' => $requiresFinality ? ($result->meta['retained_balance'] ?? '0') : 0]);

                        return $sweepRecord;
                    };
                    $sweepRecord = $requiresFinality ? DB::transaction($recordSweep) : $recordSweep();
                    if ($requiresFinality && ! $sweepRecord->wasRecentlyCreated) {
                        $driver->completeSweep($wallet->address, $tokenContract);

                        continue;
                    }

                    event(new WalletSwept($sweepRecord));

                    if ($requiresFinality) {
                        $driver->completeSweep($wallet->address, $tokenContract);
                    }

                    $sweptCount++;
                } elseif ($requiresFinality && ($result->meta['nothing_to_sweep'] ?? false)) {
                    $this->line('XRP balance is retained for the account reserve; no payment was sent.');
                } else {
                    $errMsg = $result->errorMessage ?? 'Unknown error';
                    $this->error("✗ Sweep failed for [{$wallet->address}]: {$errMsg}");
                    Log::error("Sweep failed for {$wallet->address}: {$errMsg}");
                }
            } catch (\Throwable $e) {
                $this->error("✗ Sweep exception for [{$wallet->address}]: ".$e->getMessage());
                Log::error("Sweep exception for {$wallet->address}: ".$e->getMessage());
            }
        }

        $this->info("Sweep pass complete. Swept {$sweptCount} sub-wallets.");

        return self::SUCCESS;
    }

    private function markCoveredDeposits(Builder $deposits, string $networkType, TransactionReceipt $receipt, array $meta, array $updates): void
    {
        if ($networkType === 'litecoin') {
            foreach ($receipt->raw['vin'] ?? [] as $input) {
                if (isset($input['txid'], $input['vout'])) {
                    (clone $deposits)->where('tx_hash', $input['txid'])->where('log_index', $input['vout'])->update($updates);
                }
            }

            return;
        }

        $sourceLedger = (int) ($meta['source_ledger'] ?? 0);
        $remaining = (string) ($meta['amount'] ?? '0');
        if ($sourceLedger <= 0 || $sourceLedger >= $receipt->blockNumber || bccomp($remaining, '0', 8) <= 0) {
            return;
        }

        // XRP pools deposits in the account balance. Allocate the swept amount to whole deposits in ledger order.
        $eligible = (clone $deposits)->whereBetween('block_number', [1, $sourceLedger])
            ->orderBy('block_number')->orderBy('id')->lockForUpdate()->get(['id', 'amount']);
        foreach ($eligible as $deposit) {
            $amount = (string) $deposit->amount;
            if (bccomp($amount, $remaining, 8) > 0) {
                break;
            }
            if (bccomp($amount, '0', 8) > 0) {
                (clone $deposits)->whereKey($deposit->getKey())->update($updates);
                $remaining = bcsub($remaining, $amount, 8);
            }
        }
    }
}

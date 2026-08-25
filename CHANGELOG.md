# Changelog

All notable changes to `mrokwor/blockchain-sdk-php` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [v1.0.14] - 2026-08-25

### 🔒 Security & Accounting Audit Remediation

#### **Transport & Secret Security**
- **SEC-01 (TLS Verification)**: Enabled TLS certificate verification by default (`verify => true`) across `RpcClient` and all network drivers (`EvmDriver`, `SolanaDriver`, `TronDriver`, `BitcoinDriver`).
- **SEC-02 (Explicit Secret Modes)**:
  - Added self-describing key prefixes: `enc:v1:` for AES-256 encrypted keys and `plain:` for intentional plaintext keys.
  - `BlockchainManager::decryptSecret()` now fails closed with a fatal exception if an `enc:v1:` key fails decryption, preventing corrupted ciphertext from propagating as plaintext keys.
  - `GenerateMasterWalletsCommand` automatically normalizes legacy unprefixed keys into `enc:v1:` or `plain:`.

#### **Deposit Accounting & Transfer Indexing**
- **ACC-01 & ACC-04 (Transfer-Level Accounting & Decimals)**: Replaced balance snapshots with exact on-chain transfer event indexing in `EvmDriver::getIncomingTransactions()`, storing raw base-units with BCMath calculations.
- **ACC-02 (Finality & Confirmations Policy)**: Introduced confirmation tracking state machine (`pending` -> `confirmed`) with network finality thresholds (e.g. BSC 3, Ethereum 12, Polygon 5, TRON 19).
- **ACC-03 (Duplicate Detection)**: Fixed deposit identity to strictly use on-chain `tx_hash`, ensuring multiple deposits to a sub-wallet before a sweep are accurately recorded.

#### **Transaction Lifecycle & Cryptography**
- **TX-01 (Gas Confirmation)**: Added `waitForTransactionReceipt()` with chain-adaptive safety timeouts; `sweepTokenWithGasSponsorship()` and `SweepCommand` now wait for on-chain receipt confirmation before finalizing sweeps.
- **TX-02 (Concurrency-Safe Nonce Management)**: Implemented in-memory allocated nonce tracking in `EvmDriver::getNextNonce()` to eliminate nonce collision when sweeping concurrently.
- **TX-03 (Strict Gas Policy)**: Gas estimation now fails closed on contract execution reverts, preventing gas fee burns on failed transactions.
- **CRYPTO-02 (Strict Hex Prefix Handling)**: Replaced `ltrim(..., '0x')` character masking with `strip0x()`, preventing truncation of leading `00` bytes in private keys, addresses, and ABI calldata.
- **CRYPTO-01 & TEST-01 (Test Suite)**: Expanded test suite in `tests/BlockchainSdkTest.php` covering RFC 6979 deterministic vectors, zero-byte preservation, decimal accuracy, and fail-closed secret decryption.

#### **Database & Backward Compatibility Upgrades**
- **Upgrade Migration Stub**: Added `add_accounting_columns_to_blockchainsdk_deposits_table.php.stub` under publishable tag `blockchainsdk-upgrade-migrations` with `Schema::hasColumn` safety checks for existing production databases.
- **Composite Unique Index**: Added composite uniqueness constraint `['network', 'tx_hash', 'log_index']` ensuring multiple transfers within a single transaction or block are individually recorded without collision.

---

## [v1.0.13] - 2026-08-25

### Added
- **`EvmDriver::getLatestIncomingTransaction(string $address, ?string $tokenContract = null)`**:
  - Introduces a unified transaction discovery method returning `['tx_hash' => '...', 'from_address' => '...']`.
  - Added keyless Blockscout V2 API support (`/api/v2/addresses/{address}/token-transfers` and `/api/v2/addresses/{address}/transactions`) across EVM chains (BSC, Polygon, Arbitrum, Base, Optimism, Ethereum).
  - Enhanced `eth_getLogs` lookback with single 2,000-block window and `topics[1]` sender extraction.

### Fixed
- **Deposit Ledger Resolution in `MonitorCommand`**:
  - Fixed token symbol mapping: resolved array keys from `Blockchain::getSupportedTokens()` so token symbols are saved accurately (e.g. `USDC`, `USDT`) instead of falling back to default `TOKEN`.
  - Populated `from_address` column in `blockchainsdk_deposits` table for both ERC-20/BEP-20 and native currency deposits.
  - Eliminated fallback pseudo transaction hashes (`detected_...`) by fetching real on-chain transaction hashes.

---

## [v1.0.12] - 2026-08-25

### Fixed
- **Web Context Execution for `Artisan::call()`**:
  - Moved command registration `$this->commands([...])` outside the `if ($this->app->runningInConsole())` block in `BlockchainServiceProvider.php`.
  - Allows `blockchainsdk:monitor`, `blockchainsdk:sweep`, and `blockchainsdk:generate-master-wallets` to run seamlessly when invoked via HTTP controllers, web routes, or web-based schedulers without throwing `CommandNotFoundException`.

### Changed
- **Uniform CLI Command Signatures**:
  - Standardized command signatures across all 3 console commands to support both positional arguments (`{network?}`) and named options (`{--network=}`):
    - `php artisan blockchainsdk:generate-master-wallets --network=bsc`
    - `php artisan blockchainsdk:monitor --network=bsc --once`
    - `php artisan blockchainsdk:sweep --network=bsc --token=USDC --sponsor`
- Updated `README.md` documentation and command reference tables to reflect the new `--network` option syntax.

---

## [v1.0.11] - 2026-08-25

### Added
- **Database-backed Execution Engine for `MonitorCommand`**:
  - Implemented stateless, single-pass deposit scan designed for Laravel Scheduler / cron execution.
  - Queries RPC balance across active sub-wallets, captures incoming transaction hashes, inserts rows into `blockchainsdk_deposits`, and dispatches the `DepositConfirmed` event.
- **Database-backed Execution Engine for `SweepCommand`**:
  - Implemented sub-wallet iteration querying active sub-wallets with non-zero balances.
  - Automated gas-sponsored token sweeps (`sweepTokenWithGasSponsorship`) and direct native sweeps into configured Master Cold Vaults.
  - Records transaction records in `blockchainsdk_sweeps`, marks deposits as `is_swept = true`, and unconditionally dispatches the `WalletSwept` event.
- **`BlockchainSdkWallet` Stub Methods**:
  - Added direct `$wallet->getBalance(?string $tokenContract = null)` helper method.
  - Added direct `$wallet->sweep(?string $tokenContract = null, bool $sponsor = true)` helper method.

---

## [v1.0.10] - 2026-08-24

### Added
- Initial multi-chain driver suite (EVM, Bitcoin, Solana, TRON).
- Gas station fueling and automated sponsorship architecture (`fuelSubWallet`, `sweepTokenWithGasSponsorship`).
- Publishable Eloquent models (`BlockchainSdkWallet`, `BlockchainSdkDeposit`, `BlockchainSdkSweep`) and database migrations.
- `blockchainsdk:generate-master-wallets` CLI tool with automatic AES-256 `.env` key encryption.

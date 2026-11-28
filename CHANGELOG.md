# Changelog

All notable changes to `mrokwor/blockchain-sdk-php` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [v1.2.1] - 2026-11-28

### Fixed

- Master and gas wallet generation now uses the same labeled environment-file sections in both modes. New encrypted recovery phrases get a dedicated section. Existing entries stay in place, and reruns reuse the existing headings.
- Running the legacy generator without force preserves existing gas addresses while still normalizing unprefixed gas private keys.

---

## [v1.2.0] - 2026-11-28

### Added

- Litecoin native SegWit and XRP Ledger wallet generation, signing, deposit monitoring, and sweeping. XRP also supports configured issued tokens, explicit trust-line setup, and destination tags.
- Opt-in BIP-39 recovery phrase generation for master and gas wallets. The SDK stores one encrypted phrase and writes the derived private keys to the existing gas key settings. Legacy credentials and custom master addresses are preserved by default.
- Persistent sweep state support for LTC and XRP, with Laravel cache-backed locking and receipt-based reconciliation.

### Fixed

- LTC and XRP monitoring follows history pagination. The monitor checks pending LTC deposits directly by transaction ID.
- LTC sweep reconciliation matches spent outputs. XRP reconciliation applies the swept amount to whole eligible deposits in ledger order. XRP retries resubmit the same signed transaction while it remains valid.
- TRON's latest incoming transaction lookup now uses the configured RPC nodes.
- ERC-20 sends resolve decimals from the token configuration or contract when callers supply a decimal amount without explicit decimals. Callers can still pass exact base units.

### Documentation

- Added the [v1.2.0 upgrade guide](README.md#upgrading-to-v120-from-v111) and documented Alchemy setup through per-chain RPC URLs.

---

## [v1.1.1] - 2026-10-30

### 🚀 Non-EVM Incoming Deposit Detection & Multi-Chain Monitor Upgrade

This release resolves non-EVM incoming deposit detection across TRON, Solana, and Bitcoin by implementing native and token incoming transaction parsing in `TronDriver`, `SolanaDriver`, and `BitcoinDriver`, and standardizing the contract interface and monitor command.

---

### 1. 🛡️ NetworkDriverInterface Ingestion Contract

- **Added `getIncomingTransactions()` to `NetworkDriverInterface`**:
  - `getIncomingTransactions(string $address, ?string $tokenContract = null, int $decimals = 18, int $limit = 10): array`
  - Guarantees universal transaction scanning across all supported network driver implementations, returning normalized transfer records:
    - `tx_hash` (string): On-chain transaction identifier.
    - `log_index` (int): Event log, instruction, or output index for disambiguating multiple transfers in the same transaction.
    - `block_number` (int|null): Height of the block containing the transfer.
    - `from_address` (string|null): Originating sender address.
    - `to_address` (string): Monitored recipient address.
    - `amount_raw` (string): Exact atomic base units (wei, lamports, sun, satoshis).
    - `amount` (string): Arbitrary-precision decimal representation formatted according to the token or chain decimals.
    - `decimals` (int): Number of decimals for the currency or token.
    - `confirmations` (int): Calculated confirmations against the current head block or slot height.

---

### 2. ⚡ Multi-Chain Driver Implementations

- **`TronDriver::getIncomingTransactions()`**:
  - Added full TRC20 and native TRX transfer detection via TRONGrid/TRONStack APIs (`v1/accounts/{address}/transactions/trc20` and `v1/accounts/{address}/transactions`).
  - Added strict transaction receipt verification via `wallet/gettransactioninfobyid` to ensure failed or reverted transactions (`receipt.result !== 'SUCCESS'`) are rejected.
  - Verifies TRC20 `ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef` event topics, contract address, and recipient hex/base58 conversion.
  - Computes block confirmations against `wallet/getnowblock`.
- **`SolanaDriver::getIncomingTransactions()`**:
  - Added SPL token transfer detection by querying owner Associated Token Accounts (ATAs) via `getTokenAccountsByOwner` and scanning `getSignaturesForAddress`.
  - Added support for both top-level and inner instruction parsing (`transfer` and `transferChecked`) across standard `spl-token` and `spl-token-2022` programs.
  - Added native SOL transfer detection via system program instructions (`transfer`), tracking inner instruction indexes to prevent collision.
  - Computes confirmations against current slot (`getSlot`).
- **`BitcoinDriver::getIncomingTransactions()`**:
  - Added Esplora / Mempool REST API ingestion (`address/{address}/txs`).
  - Excludes outgoing change outputs where the monitored address appears in `vin.prevout`.
  - Indexes output positions (`vout` index) to accurately distinguish multi-output transactions.
  - Tracks confirmed block height against `blocks` tip height or marks unconfirmed mempool transactions as 0 confirmations.

---

### 3. 🔍 MonitorCommand Robustness & Precision

- **Removed Silent Fallback**:
  - Removed `method_exists` check fallback to empty array `[]`, ensuring all drivers implement and execute `getIncomingTransactions()`.
- **Block Height Tracking**:
  - Updates `$pendingDeposit->block_number` upon confirmation rather than leaving it null or unindexed.
- **Accurate Native Decimals & Thresholds**:
  - Applies chain-specific native decimals during scanning:
    - TRON: 6 decimals (`SUN`)
    - Solana: 9 decimals (`Lamports`)
    - Bitcoin: 8 decimals (`Satoshis`)
    - EVM: 18 decimals (`Wei`)
  - Corrected native dust threshold condition to apply EVM-specific dust threshold (`100000000000000` wei) only for 18-decimal chains, allowing legitimate micro-deposits on Bitcoin, TRON, and Solana.

---

### 4. 🧪 Comprehensive Unit & Integration Tests

- Added `IncomingTransactionsTest.php` in `tests/` verifying TRON TRC20/native transfer detection, Solana SPL/native instruction parsing, and Bitcoin change output filtering with PHPUnit mocks.

---

## [v1.1.0] - 2026-10-01

### 🚀 High-Performance Ingestion, Fee Accounting & Multi-Chain Architecture Upgrade

This release brings end-to-end fee accounting, universal on-chain receipt extraction across all 4 chain families, high-throughput block-level deposit ingestion with chunked RPC pagination, storage-agnostic address indexing, and strict base-unit validation to prevent production transaction reverts.

---

### 1. 🛡️ Transaction Signer & Base-Unit Guard Clauses

- **Strict Validation on `EvmTransactionSigner::buildErc20TransferData()`**:
  - Added an assertion guard verifying that `$amountWei` is a non-empty string of pure base-10 digits (`/^\d+$/`).
  - Previously, passing a float-string (e.g. `"6.0"` instead of `"6000000000000000000"`) triggered an unhandled `gmp_init(): Argument #1 ($num) is not an integer string` PHP fatal exception.
  - Now throws an explicit `\InvalidArgumentException` detailing the invalid input and pointing directly to `Decimal::toBaseUnit()`.
- **Pre-Flight Input Guards on `EvmDriver::sweep()`**:
  - Added base-unit validation on both token and native currency sweep methods. Any input containing decimal points is rejected prior to ABI calldata encoding or gas estimation, preventing premature transaction broadcasts with invalid payloads.
- **Utility Expansion in `BlockchainSdk\Crypto\Decimal`**:
  - Added `Decimal::fromBaseUnit(string|int $baseUnit, int $decimals, ?int $scale = null): string` for arbitrary-precision reverse formatting from atomic base units (wei, lamports, sun, satoshis) into human-readable strings.

---

### 2. 🧾 Universal On-Chain Transaction Receipts & Fee Accounting

- **Added `BlockchainSdk\DTOs\TransactionReceipt`**:
  - Normalized, multi-chain DTO encapsulating:
    - `txHash` (string): On-chain transaction identifier.
    - `blockNumber` (int): Height of the block containing the transaction.
    - `gasUsed` (string): Gas units, compute units, energy/bandwidth, or satoshis consumed.
    - `effectiveGasPrice` (string): Gas price in base units (wei, lamports, etc.).
    - `isSuccessful` (bool): True if transaction execution succeeded without EVM revert or chain errors.
    - `logs` (array): Event logs emitted during execution.
    - `raw` (array): Full provider RPC receipt payload.
  - **Backward-Compatible `\ArrayAccess` Support**: Implements `ArrayAccess`, ensuring legacy code accessing `$receipt['blockNumber']` or `$receipt['gasUsed']` continues to work seamlessly without refactoring.
  - **Monetary Methods**:
    - `feeWei(): string`: Returns total fee in atomic base units via `bcmul($gasUsed, $effectiveGasPrice, 0)`.
    - `feeSpent(int $decimals = 18): string`: Returns human-readable native currency fee formatted to 12 decimal places.
- **Enriched `BlockchainSdk\DTOs\TransactionResult`**:
  - Added optional `public readonly ?TransactionReceipt $receipt = null`.
  - Added `$result->feeSpent(int $decimals = 18): ?string` helper method to directly expose native fees spent on successful broadcasts.
- **Universal Receipt Methods on `NetworkDriverInterface`**:
  - `getTransactionReceipt(string $txHash): ?TransactionReceipt`: Queries node once for immediate mined receipt.
  - `waitForTransactionReceipt(string $txHash, int $timeoutSeconds = 60, int $pollIntervalMs = 2000): TransactionReceipt|array|null`: Polls with chain-adaptive timeouts until mined or timeout reached.
- **Multi-Chain Driver Implementations**:
  - **`EvmDriver`**: Maps `eth_getTransactionReceipt` fields (`blockNumber`, `gasUsed`, `effectiveGasPrice`, `status`, `logs`).
  - **`TronDriver`**: Queries `wallet/gettransactioninfobyid`, extracting `fee` in sun and contract execution status.
  - **`SolanaDriver`**: Queries `getTransaction` with `jsonParsed` encoding, extracting `meta.fee` in lamports and slot height.
  - **`BitcoinDriver`**: Queries Mempool / Blockstream `/tx/{txid}`, extracting confirmed satoshi fee and block height.

---

### 3. 💼 End-to-End `SweepExecutor` & Accounting Orchestration

- **Added `BlockchainSdk\Services\SweepExecutor`**:
  - Unified orchestration service eliminating duplicated fee and sponsorship calculations across applications.
  - **Two-Step EVM Sponsorship**: For ERC-20/BEP-20 tokens, automatically fuels sub-wallets from the configured master gas key, awaits fuel transaction confirmation via `waitForTransactionReceipt()`, and then sweeps the token into the destination vault.
  - **Single-Step Non-EVM Sponsorship**: Invokes native driver gas sponsorship for Tron and Solana (`sweepTokenWithGasSponsorship`).
  - **Full Audit Accounting**: Resolves on-chain receipts for both sponsorship and sweep transactions, computing total fees via `bcadd`.
- **Added `BlockchainSdk\DTOs\SweepResult`**:
  - Exposes:
    - `transaction` (`TransactionResult`): Underlying token sweep transaction result.
    - `sweepFeeSpent` (string): Native gas fee consumed by the token transfer.
    - `sponsorshipFeeSpent` (string): Native gas fee consumed by the master gas dispenser fueling the sub-wallet.
    - `sponsorshipTxHash` (?string): Transaction hash of the gas station funding transfer.
    - `totalFeeSpent` (string): Cumulative gas cost (`sweepFeeSpent` + `sponsorshipFeeSpent`).
    - `succeeded(): bool`: Convenience status helper.
    - `txHash(): ?string`: Convenience hash getter.

---

### 4. ⚡ High-Throughput Block-Level Ingestion Engine

- **RPC Log Chunking & Rate-Limit Resiliency in `EvmDriver`**:
  - Added `getLogsChunked(int $fromBlock, int $toBlock, array $filter): array`:
  - Automatically slices block ranges into provider-safe windows using `log_chunk_size` from configuration (defaults to 2,000 blocks; set to 10 for Alchemy Free Tier).
  - Eliminates `-32602 Log response size exceeded` exceptions when scanning historical block spans.
- **Block-Level Transfer Log Primitive**:
  - Added `EvmDriver::getTransferLogs(int $fromBlock, int $toBlock, ?string $tokenContract = null, ?int $decimals = 18): array`.
  - Scans ERC-20 `Transfer(address,address,uint256)` event topics across a block span in single RPC passes.
  - Returns parsed, structured logs containing `from_address`, `to_address`, `amount_raw`, `amount`, `decimals`, `tx_hash`, `log_index`, and `block_number`.
- **`--strategy=block-ingest` in `MonitorCommand`**:
  - Added `--strategy=per-wallet` (default) and `--strategy=block-ingest` CLI options.
  - `block-ingest` transforms deposit discovery from **O(wallets × tokens)** RPC queries to **O(blocks)** RPC queries.
  - Queries new blocks once, matches incoming transfer recipients against the watched sub-wallet index in-memory, and registers confirmed deposits without individual wallet polling.
- **Resumable Checkpointing**:
  - Stored using Laravel's `Cache` facade (`blockchainsdk:checkpoint:{network}`).
  - Automatically adapts to whichever cache store is configured (`file`, `redis`, `database`, `array`), ensuring crash-resilient scanning on shared hosting as well as enterprise infrastructure.

---

### 5. 🗄️ Storage-Agnostic Address Index (Shared Hosting to Enterprise)

- **Added `BlockchainSdk\Contracts\AddressIndexInterface`**:
  - Contract defining `sync(iterable $addresses): void`, `contains(string $address): bool`, and `resolve(string $address): ?int`.
- **Implementations**:
  - **`ArrayAddressIndex`**: Fast in-memory PHP hash-map (`$map[strtolower($address)] = $walletId`). Zero infrastructure requirements; uses ~1MB for 5,000 wallets. Recommended for shared hosting and test suites.
  - **`DatabaseAddressIndex`**: Direct SQL database lookups via Laravel `DB` facade without Redis requirements.
  - **`RedisAddressIndex`**: High-performance Redis hash-map (`HSET`/`HEXISTS`/`HGET`) designed for 100,000+ active sub-wallets.
- **Added `BlockchainSdk\Services\AddressIndex\AddressIndexFactory`**:
  - Auto-detection factory resolving the best available strategy.
  - Non-throwing Redis ping check falls back seamlessly to `ArrayAddressIndex` if Redis is unreachable or uninstalled.
- **Configuration in `config/blockchainsdk.php`**:
  - Added `address_index` configuration block supporting `BLOCKCHAIN_ADDRESS_INDEX=auto|array|database|redis`.

---

### 6. 🔄 Ordered Token-First Sweep Pipeline

- **`--all` Flag in `SweepCommand`**:
  - Added `{--all : Sweep all tokens first, then sweep native balance last}` to `blockchainsdk:sweep`.
  - Phase 1 iterates through all configured and enabled tokens on the network, sweeping them with gas sponsorship.
  - Phase 2 sweeps the remaining native currency balance last.
  - Prevents native gas from being swept prematurely, which previously stranded un-swept ERC-20/BEP-20 tokens.
- **Accurate Fee Recording in `blockchainsdk_sweeps`**:
  - `SweepCommand` now waits for on-chain receipt confirmation and records exact confirmed gas fees in `fee_spent` instead of recording 0.

---

### 7. 🧪 Expanded Test Suite

- Added test cases covering:
  - Strict input validation rejecting human-readable decimals in `EvmTransactionSigner::buildErc20TransferData()`.
  - `TransactionReceipt` DTO construction, BCMath fee calculations, and `ArrayAccess` compatibility.
  - `TransactionResult` receipt encapsulation and forwarding.
  - `ArrayAddressIndex` case-insensitive address synchronization and resolution.
  - `AddressIndexFactory` auto-selection and fallback mechanics.
  - `SweepExecutor` complete orchestration, mock driver interaction, and fee aggregation.
  - Multi-chain driver receipt extraction methods.

---

## [v1.0.15] - 2026-08-25

### 🎯 Final Audit Sign-Off Remediations

- **ACC-01 / ACC-03 (Full Event Identity & Idempotency)**:
  - `MonitorCommand` now queries and stores deposits strictly by `where('network', $network)->where('tx_hash', $txHash)->where('log_index', $logIndex)`.
  - Persists `log_index`, `block_number`, `amount_raw`, and `decimals` to capture multiple `Transfer` events occurring within the same transaction or block.
- **ACC-04a (Arbitrary-Precision Monetary Checks)**:
  - Removed all `(float)` conversions in `MonitorCommand`; zero and dust threshold checks now execute via exact BCMath operations (`bccomp($amountRaw, '0') > 0` and `bccomp($amountRaw, '100000000000000') > 0`).
- **ACC-04b (Authoritative Token Metadata & Strict Decimals)**:
  - `EvmDriver::getBalance()` now consults configured token metadata first (`config['tokens']`).
  - If a contract's on-chain `decimals()` query fails and is not in configuration, it **fails explicitly with a descriptive exception** rather than silently assuming 18 decimals.
- **TX-02 (Universal Distributed & Multi-Process Nonce Manager)**:
  - Replaced process-local static array with an atomic distributed lock via `Cache::lock()` for Laravel multi-worker/multi-server setups and native OS file locking (`flock(LOCK_EX)`) for standalone plain PHP multi-process setups.
- **TEST-01 (Regression Tests)**:
  - Added regression test cases in `tests/BlockchainSdkTest.php` for unresolvable decimal failures and arbitrary-precision threshold validation.

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

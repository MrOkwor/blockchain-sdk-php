# Blockchain SDK for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrokwor/blockchain-sdk-php.svg?style=flat-square)](https://packagist.org/packages/mrokwor/blockchain-sdk-php)
[![Total Downloads](https://img.shields.io/packagist/dt/mrokwor/blockchain-sdk-php.svg?style=flat-square)](https://packagist.org/packages/mrokwor/blockchain-sdk-php)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/mrokwor/blockchain-sdk-php/php?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/mrokwor/blockchain-sdk-php.svg?style=flat-square)](LICENSE.md)

A high-performance, **zero-third-party-cryptography** blockchain SDK for PHP 8.2+ and Laravel (10, 11, 12, and 13). Generate multi-chain custodial/deposit addresses, validate addresses with EIP-55/Base58/Bech32 checksums, sign transactions locally and offline, estimate network fees, query token balances, auto-fuel gas to deposit sub-wallets, track deposits with database models, and sweep customer funds into master cold vaults across **17+ supported blockchains** including **EVM (Ethereum, BNB Smart Chain, Polygon, Arbitrum, Base, Optimism, Avalanche, Fantom, Cronos, Linea, Scroll, zkSync, Celo, Mantle)**, **Solana**, **Bitcoin (Native SegWit Bech32)**, and **TRON**.

> 💡 **Looking for working examples?** Check out the official **[blockchain-sdk-php-examples](https://github.com/MrOkwor/blockchain-sdk-php-examples)** repository for a complete runnable Laravel application featuring multi-chain wallet generation, deposit monitoring, transaction explorer discovery, and automated gas-sponsored token sweeps.

---

## 📚 Examples & Demo Application

We maintain a complete, ready-to-run demo application in the **[MrOkwor/blockchain-sdk-php-examples](https://github.com/MrOkwor/blockchain-sdk-php-examples)** repository. It includes:

- **Interactive Wallet Generator**: Generate and inspect multi-chain keypairs across EVM, Solana, TRON, and Bitcoin.
- **Address Validation Playground**: Validate customer addresses with instant EIP-55, Base58, and Bech32 checksum verification.
- **Live Deposit Monitoring**: Real-time balance and incoming transaction detection across 17+ chains.
- **Automated Gas Station Sweeper**: Test end-to-end token sweeps with automatic native gas fueling on dry deposit addresses.
- **Production Controllers & Blade Views**: Copy-pasteable real-world Laravel controllers, models, and background artisan worker setups.

---

## Features

- **Zero External Cryptographic Dependencies**: Pure-PHP mathematical primitives for Secp256k1 (with GMP acceleration), EVM Keccak-256 permutation, RFC-6979 deterministic nonce generation, Base58/Base58Check, and Bech32.
- **17+ Supported Blockchains & Standard Tokens**: Pre-configured registry of supported token contracts, decimals, and enable/disable statuses for USDT, USDC, WBTC, DAI, LINK, UNI, and more.
- **Multi-Chain Address Validation & Laravel Rules**: Built-in address validation across all 17 chains (EVM 0x hex & EIP-55 checksums, Solana Base58 32-byte pubkeys, TRON Base58Check T-addresses with 0x41 prefix, Bitcoin Bech32/Base58) and ready-to-use Laravel `BlockchainAddress` validation rule.
- **Separated Master Vaults & Hot Gas Station Architecture**: Clean architectural separation between Master Cold Vault receiving destinations and Hot Gas Station fee dispensers. Modify your master receiving vaults anytime without touching or exposing your gas wallets.
- **Automated Gas Station & Master Vault Generator**: Generate master receiving vaults and hot gas station credentials in one command (`php artisan blockchainsdk:generate-master-wallets`) with automated AES-256 encryption and formatted `.env` blocks.
- **Automated Gas Station & Fee Sponsorship**: Solves the "empty gas tank" problem. Automatically detects if a customer sub-wallet has 0 native currency (0 BNB/TRX/ETH/SOL) and dispatches exact gas from a Master Hot Gas Wallet before executing the token sweep.
- **Publishable Migrations & Eloquent Models**: Publish customizable database schemas and models (`BlockchainSdkWallet`, `BlockchainSdkDeposit`, `BlockchainSdkSweep`) directly into `app/Models/`.
- **Event-Driven Value Crediting**: Dispatches lifecycle events (`DepositConfirmed`, `WalletSwept`) to give balance value to users immediately upon deposit confirmation or after vault consolidation.
- **Multi-Node Failover RPC Client**: Built-in round-robin health-checking and automatic failover across multiple fallback RPC endpoints.
- **Automated Vault Sweeper**: Compute network fees and automatically sweep funds from customer deposit addresses into master cold vaults with token-first sweep priority (`--all`).
- **High-Performance Event Log Ingestion (`block-ingest`)**: Scale from hundreds of wallets to millions. Ingests blocks once in $O(\text{blocks})$ time via `eth_getLogs` and matches incoming transfers in $O(1)$ sub-millisecond lookups.
- **Storage-Agnostic Address Indexing (`AddressIndexInterface`)**: Universal indexing strategies for `redis`, `database` (relational SQL), and in-memory `array` (runs anywhere, including shared hosting without Redis).
- **Exact Receipt & Fee Accounting (`TransactionReceipt`)**: Normalized on-chain transaction receipt parser across EVM, TRON, Solana, and Bitcoin with exact `feeSpent()` and `feeWei()` calculation.
- **Full Laravel 10, 11, 12, and 13 Support**: First-class Service Provider, Facade (`Blockchain::driver(...)`), publishable `config/blockchainsdk.php`, and ready-to-use Artisan commands (`blockchainsdk:generate-master-wallets`, `blockchainsdk:sweep`, `blockchainsdk:monitor`).
- **Offline & Secure Signing**: Private keys never leave your application server; transactions are constructed and signed locally before raw broadcast.

---

## Supported Networks & Driver Identifiers

The SDK supports **19 configured networks** out of the box:

| Driver Code | Network Name | Ecosystem | Address Format | Signing Algorithm |
| :--- | :--- | :--- | :--- | :--- |
| `ethereum` | Ethereum Mainnet | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `bsc` | BNB Smart Chain | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `polygon` | Polygon POS | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `arbitrum` | Arbitrum One | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `optimism` | OP Mainnet | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `base` | Base | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `avalanche` | Avalanche C-Chain | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `fantom` | Fantom Opera | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `cronos` | Cronos Chain | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `linea` | Linea | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `scroll` | Scroll | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `zksync` | zkSync Era | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `celo` | Celo | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `mantle` | Mantle | EVM | EIP-55 Hex (`0x...`) | Secp256k1 + EIP-155 / RLP |
| `solana` | Solana | Solana | Base58 Public Key | Ed25519 (Libsodium) |
| `tron` | TRON | TRON | Base58Check (`T...`) | Secp256k1 + SHA-256 |
| `bitcoin` | Bitcoin | UTXO | Native SegWit Bech32 (`bc1q...`) | Secp256k1 + BIP-143 |
| `litecoin` | Litecoin | UTXO | Native SegWit Bech32 (`ltc1q...`) | Secp256k1 + BIP-143 |
| `xrp` | XRP Ledger | XRPL | Classic Base58Check (`r...`) | Secp256k1 + SHA-512Half |

### Litecoin and XRP Ledger

These drivers work in standalone PHP and Laravel. All configured networks are available;
there is no separate database-backed network switch. Configured tokens retain the existing
`status => 'enabled'` / `status => 'disabled'` convention. No tokens are enabled on XRP by default.

- **Litecoin:** native LTC, confirmed UTXO spending, balances, incoming output detection,
  local signing, broadcasting and sweeps. The default API is `https://litecoinspace.org/api`
  (Esplora REST, not a Litecoin Core JSON-RPC endpoint). Override with `LITECOIN_RPC_URL`.
  Generated spendable wallets use native SegWit; legacy L/M addresses are supported as
  payment destinations. OmniLite, inscriptions, MWEB and Taproot spending are not supported.
- **XRP Ledger:** native XRP and configured trust-line issued currencies. Default RPCs are
  `https://xrplcluster.com` and `https://s2.ripple.com:51234`. Override with `XRP_RPC_URL`.
  MPTs, NFTs, account deletion, cross-currency path finding and multisigning are not supported.
  Keys are secp256k1 hex and addresses are classic addresses. Supply `destination_tag` when
  sending to an exchange; use `XRP_SWEEP_DESTINATION_TAG` for a tagged master vault.
- Deposits use six confirmations for LTC and one validated ledger for XRP by default.
  Override using `blockchainsdk.confirmations.litecoin` / `.xrp` in Laravel configuration.
- Configure `BLOCKCHAIN_MASTER_LITECOIN`, `BLOCKCHAIN_MASTER_XRP` and the usual
  `BLOCKCHAIN_GAS_ADDRESS_*` / `BLOCKCHAIN_GAS_KEY_*` settings. The existing master-wallet
  generator accepts either new network and can derive both from a recovery phrase in seed mode.
- XRP native sweeps retain the current ledger reserve, including owned-object reserves,
  and transaction fees. A reserve-only wallet returns `meta.nothing_to_sweep` with no hash.
  `accountStatus($address)` reports raw drops; `minimumAccountFunding()` returns XRP.

#### Configure an XRP issued token

Use a unique configuration key for each issuer; a currency symbol alone is not an asset identity.
The existing `contract` argument carries `currency:issuer`. Both fields are case-sensitive.

```php
'xrp' => [
    'type' => 'xrp',
    'currency' => 'XRP',
    'rpc_nodes' => ['https://xrplcluster.com', 'https://s2.ripple.com:51234'],
    'tokens' => [
        'USD_GATEWAY' => [
            'name' => 'USD from your selected issuer',
            'currency' => 'USD',
            'issuer' => 'REPLACE_WITH_VERIFIED_CLASSIC_ISSUER_ADDRESS',
            'decimals' => 6,
            'status' => 'enabled',
        ],
    ],
],
```

Fund the deposit account and master vault with sufficient XRP, then explicitly establish
their trust lines before accepting tokens. Enabling a configuration entry does not enroll
wallets or spend XRP automatically:

```php
$driver = $sdk->driver('xrp'); // Blockchain::driver('xrp') in Laravel
$asset = 'USD:'.$verifiedIssuer;
$enrollment = $driver->establishTrustLine($depositPrivateKey, $asset, '1000000');
$receipt = $driver->waitForTransactionReceipt($enrollment->txHash);
// Only expose the token deposit address after successful validation and issuer authorization, if required.
$balance = $driver->getBalance($depositAddress, $asset);
$payment = $driver->sendTransaction([
    'private_key' => $depositPrivateKey,
    'to' => $masterVault,
    'token_contract' => $asset,
    'amount' => '10.5', // Human-readable token units; amount_raw is optional integer accounting units.
]);
```

Trust-line tokens have variable ledger precision. This SDK integration deliberately supports
configured accounting scales of **0–8 decimal places** and amounts that fit its existing
`decimal(24,8)` accounting tables. Incoming amounts with excess precision are rejected and
reported by the monitor instead of silently rounded or credited. Configure a scale suitable
for the accepted asset; more general XRPL precision requires a separate storage upgrade.
No database migration is required for this bounded support.

Token payments check issuer identity, authorization, frozen trust lines and destination limits.
Sweep amounts account for issuer transfer fees; deposit crediting uses validated
`delivered_amount`, never the requested payment amount. Native XRP reserves remain on the
account after token/native sweeps. `--all` keeps the existing token-first ordering.

#### Pending sweeps and standalone storage

LTC/XRP broadcasts are not recorded as completed sweeps until an on-chain receipt succeeds.
The Laravel integration remembers pending signed sweep hashes in the configured cache and
uses atomic wallet locks. Use a persistent cache with lock support; do not clear pending
sweep state while transactions are unresolved. Repeated passes reconcile the same hash.

Standalone drivers default to **process-local** `MemorySweepStateStore`. For production,
inject a persistent, shared implementation of `SweepStateStoreInterface` as
`sweep_state_store` in the manager or network configuration. Its `synchronized()` method
must provide cross-process exclusion. Call `completeSweep($address, $asset)` only after
successfully confirming and recording the sweep. A result's `success` means the transaction
was accepted/submitted; it is not proof of finality. Serialize other outgoing transactions
from the same account in your application as well, to avoid XRP sequence/UTXO conflicts.

Existing Laravel installations must merge the new network, master-wallet and gas-wallet
entries into their published config. Avoid overwriting custom RPCs or token entries.

---

## Requirements

- **PHP 8.2+**
- **Extensions:**
  - `ext-gmp` (Required for Secp256k1 elliptic curve point arithmetic)
  - `ext-sodium` (Required for Solana Ed25519 signatures; enabled by default in PHP 7.2+)
  - `ext-bcmath` (Required for arbitrary-precision wei / satoshi math)
  - `ext-curl` & `ext-json`

---

## Installation

Install the package via Composer:

```bash
composer require mrokwor/blockchain-sdk-php
```

---

# Part 1: Laravel Integration Guide (Laravel 10, 11, 12, and 13)

If you are using **Laravel**, the package registers automatically via auto-discovery.

### 1. Publishing Assets

#### A. Publish Configuration File (`config/blockchainsdk.php`)
```bash
php artisan vendor:publish --tag="blockchainsdk-config"
```

#### B. Publish Database Migrations
```bash
php artisan vendor:publish --tag="blockchainsdk-migrations"
```

#### C. Publish Eloquent Models to `app/Models/`
```bash
php artisan vendor:publish --tag="blockchainsdk-models"
```

#### D. Run Migrations
```bash
php artisan migrate
```

---

### 2. Generating Master Vaults & Hot Gas Wallets

To sweep customer deposits into a central vault or sponsor network fees for dry sub-wallets, your platform needs Master Cold Vault Receiving Addresses and Hot Gas Station credentials. 

You can generate both in a single command with **automatic AES-256 encryption** and **clean, separated `.env` blocks**:

```bash
# Generate for all 17 supported blockchains (Encrypted & Saved to .env)
php artisan blockchainsdk:generate-master-wallets

# Generate for a specific blockchain network (e.g. BSC, Polygon, Solana, TRON)
php artisan blockchainsdk:generate-master-wallets --network=bsc
php artisan blockchainsdk:generate-master-wallets --network=solana
php artisan blockchainsdk:generate-master-wallets --network=tron

# Overwrite existing keys in .env
php artisan blockchainsdk:generate-master-wallets --force

# Console output only (do not write to .env)
php artisan blockchainsdk:generate-master-wallets --no-store

# Store raw plaintext private keys (disable encryption)
php artisan blockchainsdk:generate-master-wallets --no-encrypt
```

#### Generate wallets with a recovery phrase

```bash
php artisan blockchainsdk:generate-master-wallets --seed-phrase
php artisan blockchainsdk:generate-master-wallets --seed-phrase --show-seed-phrase
```

Seed mode stores one encrypted 24-word BIP-39 phrase in `BLOCKCHAIN_MASTER_SEED_PHRASE` and writes the derived gas private keys to the existing `BLOCKCHAIN_GAS_KEY_*` settings. Sweeps and gas funding keep using the configured keys. The phrase is reused when generating wallets for additional networks.

The master and gas addresses match by default. An existing `BLOCKCHAIN_MASTER_*` override takes precedence. Existing gas credentials are skipped unless you explicitly pass `--force`, which replaces the selected gas credentials and preserves configured master addresses. Previously generated independent keys cannot be recovered with the new phrase.

Display the phrase in a private terminal using `--show-seed-phrase` and import it using the matching network and derivation path in your wallet app. Keep an offline backup. The stored copy requires the Laravel `APP_KEY` for decryption. `--no-store` prints the phrase and derived addresses without writing credentials to the environment file.

| Network | Derivation path |
| --- | --- |
| EVM networks | `m/44'/60'/0'/0/0` |
| Bitcoin, native SegWit | `m/84'/0'/0'/0/0` |
| Litecoin, native SegWit | `m/84'/2'/0'/0/0` |
| TRON | `m/44'/195'/0'/0/0` |
| XRP Ledger | `m/44'/144'/0'/0/0` |
| Solana, Ed25519 | `m/44'/501'/0'` |

#### How Master & Gas Wallets Are Stored in `.env`
```env
# ==============================================================================
# BLOCKCHAIN SDK: MASTER COLD VAULT RECEIVING WALLETS
# Destination addresses where customer sub-wallet funds are swept and stored.
# ==============================================================================
BLOCKCHAIN_MASTER_ETHEREUM="0x71C8360f3a104d31a4570b9A821929342939b422"
BLOCKCHAIN_MASTER_BSC="0x55d398326f99059fF775485246999027B3197955"
BLOCKCHAIN_MASTER_SOLANA="EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v"
BLOCKCHAIN_MASTER_TRON="TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t"
BLOCKCHAIN_MASTER_BITCOIN="bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4"

# ==============================================================================
# BLOCKCHAIN SDK: HOT GAS STATION WALLETS & PRIVATE KEYS
# Wallets used solely to sponsor and fuel gas fees into sub-wallets before sweeps.
# ==============================================================================
BLOCKCHAIN_GAS_ADDRESS_ETHEREUM="0x71C8360f3a104d31a4570b9A821929342939b422"
BLOCKCHAIN_GAS_KEY_ETHEREUM="eyJpdiI6Inl1Vn...[ENCRYPTED_SECRET]..."
BLOCKCHAIN_GAS_ADDRESS_BSC="0x55d398326f99059fF775485246999027B3197955"
BLOCKCHAIN_GAS_KEY_BSC="eyJpdiI6Inl1Vn...[ENCRYPTED_SECRET]..."
BLOCKCHAIN_GAS_ADDRESS_SOLANA="EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v"
BLOCKCHAIN_GAS_KEY_SOLANA="eyJpdiI6Inl1Vn...[ENCRYPTED_SECRET]..."
BLOCKCHAIN_GAS_ADDRESS_TRON="TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t"
BLOCKCHAIN_GAS_KEY_TRON="eyJpdiI6Inl1Vn...[ENCRYPTED_SECRET]..."
```

> [!TIP]
> **Independent Separation**: You can change your `BLOCKCHAIN_MASTER_*` destination addresses anytime (e.g. directing funds to a Hardware Ledger or Gnosis Safe multisig) without affecting or resetting your `BLOCKCHAIN_GAS_*` fee sponsor keys.

---

### 2.5 Recommended Node Provider Setup (Free API Keys)

High-performance event log scanning (`eth_getLogs`) requires RPC endpoints that support topic queries and deep history. **Public community RPCs** (e.g. `rpc.ankr.com` or `binance.org`) frequently rate-limit or reject `eth_getLogs` with HTTP 429/403 errors.

For production and staging, we strongly recommend using dedicated RPC providers with generous **Free Tiers**:

#### 1. Alchemy (Recommended for EVM & Solana)
- **Free Tier**: **300,000,000 Compute Units (CU) / month** for free.
- **Supported Chains**: Ethereum, BNB Smart Chain, Polygon, Arbitrum, Base, Optimism, Solana, Tron.
- **Sign Up**: [https://dashboard.alchemy.com](https://dashboard.alchemy.com)
- **Setup in `.env`**:
  ```env
  ETHEREUM_RPC_URL="https://eth-mainnet.g.alchemy.com/v2/your-alchemy-api-key"
  BASE_RPC_URL="https://base-mainnet.g.alchemy.com/v2/your-alchemy-api-key"
  ```
  Set the corresponding `<CHAIN>_RPC_URL` for each network with a dedicated URL setting, or update that network's `rpc_nodes` in `config/blockchainsdk.php`.

> [!IMPORTANT]
> **Alchemy Free Tier 10-Block Chunking Notice**:
> Under Alchemy's Free Tier, `eth_getLogs` has a hard limit of **10 blocks per request**.
> The SDK automatically chunks EVM block queries into 10-block windows (controlled by `evm_log_chunk_size => 10` in `config/blockchainsdk.php`), completely preventing `Query exceeds max block range 10` errors!
> If you upgrade to Alchemy PAYG or a paid plan, you can increase this value in `.env`:
> ```env
> BLOCKCHAIN_EVM_LOG_CHUNK_SIZE=500
> ```

#### 2. TronGrid (Recommended for TRON)
- **Free Tier**: Free API Key for high-speed TRON node queries and events without rate throttling.
- **Sign Up**: [https://www.trongrid.io](https://www.trongrid.io)
- **Setup in `.env`**:
  ```env
  TRON_PRO_API_KEY="your-trongrid-api-key-here"
  ```

#### 3. QuickNode / Custom RPC Endpoints
You can configure custom or backup RPC nodes in `config/blockchainsdk.php`:
```php
'networks' => [
    'bsc' => [
        'rpc_nodes' => [
            env('BSC_RPC_URL', 'https://bsc-dataseed.binance.org'),
            'https://rpc.ankr.com/bsc',
        ],
    ],
],
```

---

### 3. Validating Wallet Addresses in Laravel

You can validate customer receiving/payout addresses using the `Blockchain::validateAddress()` facade or the built-in Laravel Validation Rule:

#### Option A: Using the `BlockchainAddress` Validation Rule in Form Requests / Controllers
```php
use BlockchainSdk\Laravel\Rules\BlockchainAddress;
use Illuminate\Http\Request;

public function swap(Request $request)
{
    $network = $request->input('destination_network'); // e.g. 'solana', 'bsc', 'tron', 'bitcoin'

    $request->validate([
        'recipient_address' => ['required', 'string', new BlockchainAddress($network)],
    ]);
}
```

#### Option B: Validating Directly via Facade
```php
use BlockchainSdk\Laravel\Facades\Blockchain;

// EVM (Ethereum, BSC, Polygon, Base, Arbitrum, etc.)
Blockchain::validateAddress('bsc', '0x55d398326f99059fF775485246999027B3197955'); // true
Blockchain::validateAddress('ethereum', '0xInvalidAddress123');                       // false

// Solana (Base58 32-byte public key)
Blockchain::validateAddress('solana', 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v'); // true

// TRON (Base58Check T... address)
Blockchain::validateAddress('tron', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'); // true

// Bitcoin (Bech32 SegWit & Base58 Legacy)
Blockchain::validateAddress('bitcoin', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'); // true
```

---

### 4. Generating Multi-Chain Deposit Wallets in Laravel

#### Option A: Using Eloquent Models (Recommended)
Automatically creates a wallet keypair, encrypts the private key, and associates it with a user in your database:

```php
use App\Models\BlockchainSdkWallet;

// Generates keypair and stores encrypted private key in database
$wallet = BlockchainSdkWallet::createForUser(
    userId: $user->id,
    network: 'bsc'
);

echo "Deposit Address: " . $wallet->address;
```

#### Option B: Using the `Blockchain` Facade Directly
```php
use BlockchainSdk\Laravel\Facades\Blockchain;

/** @var \BlockchainSdk\DTOs\Keypair $keypair */
$keypair = Blockchain::driver('ethereum')->generateWallet();

echo $keypair->address;     // "0x71C8360f3a104d31a4570b9A821929342939b422"
echo $keypair->privateKey;  // "0x4f3edf983ac636a65a842ce7c78d9aa706d3b113bce9c46f30d7d21715b23b1d"
echo $keypair->publicKey;   // "0x04e130f46ff5ff57a24cc634d..."
echo $keypair->mnemonic;    // Optional HD seed phrase string or null
```

---

### 5. Querying Balances & Checking Token Statuses in Laravel

```php
use BlockchainSdk\Laravel\Facades\Blockchain;

// 1. Check if a token is enabled in configuration
if (Blockchain::isTokenEnabled('polygon', 'USDT')) {
    $tokenInfo = Blockchain::findToken('polygon', 'USDT');
    
    // 2. Query token balance using smart contract address
    $balance = Blockchain::driver('polygon')->getBalance(
        address: '0xYourUserDepositAddress',
        tokenContract: $tokenInfo['contract']
    );
    
    echo "Polygon USDT Balance: " . $balance->balanceFormatted; // e.g. "150.500000"
    echo "Raw Wei / Base Units: " . $balance->balanceRaw;       // e.g. "150500000"
}

// 3. Query native currency balance (e.g. ETH, BNB, SOL, TRX, BTC)
$nativeBalance = Blockchain::driver('solana')->getBalance('SolanaAddressHere...');
echo "SOL Balance: " . $nativeBalance->balanceFormatted;
```

---

### 5.5 Discovering Incoming Deposit Transaction Hashes

When customers deposit funds into temporary sub-wallets or personal addresses, you can automatically capture the incoming blockchain transaction hash across EVM, Solana, TRON, and Bitcoin without writing low-level RPC chunking loops:

```php
use BlockchainSdk\Laravel\Facades\Blockchain;

// 1. Discover incoming ERC-20 / BEP-20 token deposit hash (e.g. USDC on BSC)
$txHash = Blockchain::driver('bsc')->getLatestIncomingTxHash(
    address: '0xDepositSubWalletAddress...',
    tokenContract: '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d' // USDC
);

// 2. Discover incoming Solana SPL token or native SOL transfer signature
$solSignature = Blockchain::driver('solana')->getLatestIncomingTxHash(
    address: 'SolanaDepositAddress...'
);

// 3. Discover incoming TRON TRC-20 (USDT) or native TRX transfer
$tronTxId = Blockchain::driver('tron')->getLatestIncomingTxHash(
    address: 'TRON_Address_Here...',
    tokenContract: 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t' // USDT
);

// 4. Discover incoming Bitcoin SegWit transaction ID
$btcTxId = Blockchain::driver('bitcoin')->getLatestIncomingTxHash(
    address: 'bc1q...'
);
```

---

### 5.6 Real-Time Deposit Monitoring & High-Performance Block Ingestion

The SDK provides an enterprise-grade deposit monitor daemon capable of scanning incoming on-chain transfers and advancing block confirmation depths until finality.

You can run the monitor in two distinct modes:

#### Mode 1: High-Performance Block Ingestion (`--strategy=block-ingest`) — Recommended
Instead of polling each sub-wallet individually over RPC ($O(\text{wallets} \times \text{tokens})$), the `block-ingest` strategy queries the blockchain **once per block window** via `eth_getLogs` and matches recipient addresses against an in-memory/Redis `AddressIndex` in **$O(1)$ time ($<0.1\text{ ms}$)**:

```bash
# High-performance block-level event ingestion (scales to millions of wallets at $0 extra RPC cost)
php artisan blockchainsdk:monitor --strategy=block-ingest

# Target a specific network
php artisan blockchainsdk:monitor bsc --strategy=block-ingest
```

- **Resumable Checkpoints**: The daemon tracks the latest scanned block height in `blockchainsdk:checkpoint:{network}` so it automatically resumes from where it left off after restarts.
- **Archive Protection**: If your worker was offline and drifts far behind the chain tip, the monitor automatically clamps lag to prevent expensive archive-node paywall errors on free RPC tiers.

#### Mode 2: Per-Wallet Polling (`--strategy=per-wallet`) — Default / Fallback
Loops through active sub-wallets in your database and checks incoming transfers for each configured token:
```bash
php artisan blockchainsdk:monitor --strategy=per-wallet
```

#### Storage-Agnostic Address Indexing (`AddressIndexInterface`)
In `block-ingest` mode, the SDK uses an address index to resolve recipient addresses in sub-millisecond time. You can choose the storage strategy that fits your server infrastructure in `config/blockchainsdk.php`:

```php
'address_index' => [
    // 'auto'     => Uses Redis if available, falls back to Array in-memory
    // 'redis'    => High-performance Redis hash/set for production scale
    // 'database' => Relational SQL table lookup for servers without Redis (shared hosting)
    // 'array'    => Ephemeral in-memory array (ideal for tests and microservices)
    'strategy' => env('BLOCKCHAIN_ADDRESS_INDEX_STRATEGY', 'auto'),
],
```

#### Scheduling in Laravel (`routes/console.php`)
Add the monitor to your Laravel scheduler to run continuously in the background:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('blockchainsdk:monitor --strategy=block-ingest')
    ->everyMinute()
    ->withoutOverlapping();
```

---

### 6. Sweeping Sub-Wallets into Master Vaults in Laravel

#### A. Sweeping Native Currency via Facade
```php
use BlockchainSdk\Laravel\Facades\Blockchain;

$driver = Blockchain::driver('ethereum');
$masterVault = Blockchain::getMasterWallet('ethereum');

$result = $driver->sweep(
    fromPrivateKey: $subWalletPrivateKey,
    toAddress: $masterVault
);

if ($result->success) {
    echo "Sweep Broadcasted. TxHash: " . $result->txHash;
}
```

#### B. Sweeping Supported Tokens via Facade
```php
use BlockchainSdk\Laravel\Facades\Blockchain;

$driver = Blockchain::driver('bsc');
$masterVault = Blockchain::getMasterWallet('bsc');
$token = Blockchain::findToken('bsc', 'USDT');

$result = $driver->sweep(
    fromPrivateKey: $subWalletPrivateKey,
    toAddress: $masterVault,
    tokenContract: $token['contract']
);
```

#### C. Sweeping Dry Wallets with Automated Gas Sponsorship
```php
use BlockchainSdk\Laravel\Facades\Blockchain;

$driver = Blockchain::driver('polygon');

// Automatically fuels exact MATIC/POL gas from master gas station -> sweeps USDT
$result = $driver->sweepTokenWithGasSponsorship(
    subWalletPrivateKey: $subWalletPrivateKey,
    masterGasPrivateKey: Blockchain::getMasterGasKey('polygon'),
    toVaultAddress:      Blockchain::getMasterWallet('polygon'),
    tokenContract:       '0xc2132D05D31c914a87C6611C10748AEb04B58e8F' // USDT
);
```

#### D. Token-First Sweeping via Artisan (`--all`)
To ensure configured ERC-20/SPL/TRC-20 tokens are swept **before** native gas currency is drained, use the `--all` flag:

```bash
# Sweeps all configured tokens first, then sweeps remaining native gas
php artisan blockchainsdk:sweep --network=bsc --all --sponsor --credit
```

#### E. Unified Sweeping Engine (`SweepExecutor`) & Exact Fee Receipts
For custom services or non-Laravel scripts, the SDK provides `SweepExecutor`, which orchestrates gas sponsorship, sweeping, and on-chain receipt extraction:

```php
use BlockchainSdk\Services\SweepExecutor;

$executor = new SweepExecutor();
$result = $executor->execute(
    driver: $driver,
    network: 'polygon',
    fromAddress: $wallet->address,
    fromPrivateKey: $wallet->private_key,
    toVaultAddress: $masterVault,
    tokenContract: $usdtContract,
    masterGasPrivateKey: $gasKey, // Optional auto-sponsorship
);

if ($result->transaction->success) {
    echo "Sweep Tx: " . $result->transaction->txHash;
    echo "Confirmed Fee Spent: " . $result->feeSpent(); // In native currency (e.g. MATIC/POL)
    echo "Gas Used: " . $result->receipt?->gasUsed;
}
```

---

### 7. Giving Value to Users After Sweeping or Depositing in Laravel

In crypto platforms and fintech applications, **"giving value"** means crediting the customer's account balance, ledger, or wallet in your application database. The SDK dispatches events so you can execute your own custom credit logic.

#### Paradigm A: Credit After Master Vault Sweep (Post-Sweep Hook)

1. Execute the sweep command with the `--credit` flag:
   ```bash
   php artisan blockchainsdk:sweep --network=bsc --token=USDT --sponsor --credit
   ```
2. Listen to `BlockchainSdk\Laravel\Events\WalletSwept` in your application:
   ```php
   namespace App\Listeners;

   use BlockchainSdk\Laravel\Events\WalletSwept;

   class CreditUserOnSweepListener
   {
       /**
        * Handle the event. Replace placeholder logic with your application's accounting flow.
        */
       public function handle(WalletSwept $event): void
       {
           $sweep  = $event->sweep;
           $wallet = $sweep->wallet; // Associated BlockchainSdkWallet instance
           
           if ($wallet && $wallet->user_id) {
               $user = \App\Models\User::find($wallet->user_id);
               
               // Example: Credit your application's user balance column
               // Replace '{{your_balance_column}}' with your actual column (e.g. 'balance', 'wallet_balance', 'usd_balance')
               $user->increment('{{your_balance_column}}', $sweep->amount);
               
               // Or call your custom accounting / ledger service:
               // LedgerService::creditUser($user->id, $sweep->amount, $sweep->token_symbol, $sweep->tx_hash);
               
               // Mark the sweep as credited in the database ledger
               $sweep->markAsCredited();
               
               logger()->info("Credited {$sweep->amount} {$sweep->token_symbol} to User #{$user->id} on sweep Tx: {$sweep->tx_hash}");
           }
       }
   }
   ```

#### Paradigm B: Immediate Credit on Confirmed Deposit (Instant UX)

If you want users to receive their platform balance as soon as the transaction is confirmed on-chain (while vault sweeping runs asynchronously in the background):

```php
namespace App\Listeners;

use BlockchainSdk\Laravel\Events\DepositConfirmed;

class CreditUserOnDepositListener
{
    /**
     * Handle the event. Replace placeholder logic with your application's accounting flow.
     */
    public function handle(DepositConfirmed $event): void
    {
        $deposit = $event->deposit;
        $wallet  = $deposit->wallet;

        if ($wallet && $wallet->user_id && !$deposit->is_credited) {
            $user = \App\Models\User::find($wallet->user_id);

            // Example: Credit your application's user balance column immediately
            // Replace '{{your_balance_column}}' with your actual column name
            $user->increment('{{your_balance_column}}', $deposit->amount);

            $deposit->markAsCredited();
        }
    }
}
```

---

### 8. Laravel Artisan Commands Reference

| Command | Description | Options |
| :--- | :--- | :--- |
| `php artisan blockchainsdk:generate-master-wallets` | Generates master cold vault receiving addresses and hot gas station credentials in separated `.env` blocks | `--network=`, `--no-encrypt`, `--no-store`, `--force`, `--seed-phrase`, `--show-seed-phrase` |
| `php artisan blockchainsdk:sweep` | Sweeps sub-wallets into central cold vault | `--network=`, `--token=` (symbol/contract), `--all` (token-first sweep), `--sponsor` (auto-gas), `--credit` (dispatch value event) |
| `php artisan blockchainsdk:monitor` | Multi-chain background deposit confirmation listener | `--network=`, `--token=`, `--strategy=block-ingest\|per-wallet`, `--once` (run single pass) |
| `php artisan vendor:publish --tag="blockchainsdk-config"` | Publishes `config/blockchainsdk.php` | `--force` (overwrite existing) |
| `php artisan vendor:publish --tag="blockchainsdk-migrations"` | Publishes database migrations | `--force` (overwrite existing) |
| `php artisan vendor:publish --tag="blockchainsdk-models"` | Publishes Eloquent models to `app/Models/` | `--force` (overwrite existing) |

---

### Upgrading to v1.2.0 from v1.1.1

Existing wallets keep their addresses and private keys. This upgrade requires no new SDK database migrations, wallet rotation, or recovery phrase generation.

1. Run `composer update mrokwor/blockchain-sdk-php` if your Composer constraint allows v1.2.0. If it is pinned to an older version, update the constraint to `^1.2` first.
2. Compare your published `config/blockchainsdk.php` with the package copy under `vendor/mrokwor/blockchain-sdk-php/config/`. Merge the settings you need and preserve your custom RPCs, tokens, and vault addresses. Refresh the Laravel config cache with `php artisan config:cache` if your deployment uses it.
3. If you use TRON, verify that its configured RPC nodes target the intended network and run a monitor pass. For ERC-20 sends, the SDK now resolves the token's decimals from configuration or the contract. If neither provides usable decimals, pass the correct `decimals` or an exact `amount_raw`.

#### Enable Litecoin or XRP

- Merge the relevant `networks`, `confirmations`, `master_wallets`, and `master_gas_wallets` entries into your published config. Set `LITECOIN_RPC_URL` or `XRP_RPC_URL` if using a dedicated provider. Set `XRP_SWEEP_DESTINATION_TAG` if your XRP vault requires a tag.
- Use a persistent cache with lock support for pending sweeps. Keep that state while a transaction is unresolved. Test a small deposit and sweep before enabling production traffic.
- Fund XRP accounts for the ledger reserve. XRP issued tokens also require explicit trust lines. See [Litecoin and XRP Ledger](#litecoin-and-xrp-ledger) for setup and reserve behavior.

#### Generate new wallets with a recovery phrase

Run `php artisan blockchainsdk:generate-master-wallets --seed-phrase`. Existing gas credentials are skipped by default, and custom master addresses are preserved. Add `--show-seed-phrase` in a private terminal to back up or import the phrase. Existing independently generated wallets still require their original keys for recovery. See [Generate wallets with a recovery phrase](#generate-wallets-with-a-recovery-phrase).

### 9. Upgrading to v1.1.0 from v1.0.x

`v1.1.0` introduces enterprise-scale block event ingestion (`--strategy=block-ingest`), storage-agnostic address indexing (`AddressIndexInterface`), exact on-chain fee accounting via `TransactionReceipt`, and token-first sweeping (`--all`).

Follow these steps to upgrade an existing application:

#### Step 1: Update Composer Dependency
```bash
composer update mrokwor/blockchain-sdk-php
```

#### Step 2: Republish Configuration (`config/blockchainsdk.php`)
`v1.1.0` introduces new configuration options for `evm_log_chunk_size`, `address_index`, and provider-agnostic `rpc_nodes`.

Force republish the configuration file:
```bash
php artisan vendor:publish --tag="blockchainsdk-config" --force
```

> [!CAUTION]
> If you have custom tokens, contract addresses, or custom master vaults in `config/blockchainsdk.php`, back them up first, or manually merge the new sections:
> - `'evm_log_chunk_size' => (int) env('BLOCKCHAIN_EVM_LOG_CHUNK_SIZE', 10)`
> - `'address_index' => [...]`
> - Provider `.env` RPC lookups under `'rpc_nodes' => array_values(array_filter([...]))`

#### Step 3: Configure Dedicated RPC Providers in `.env`
Public community RPC nodes often block or throttle `eth_getLogs`. To use the new high-performance block ingestion engine, set your dedicated provider URL in `.env`:
```env
# Dedicated node providers (e.g. Alchemy, QuickNode, Infura)
ETHEREUM_RPC_URL="https://eth-mainnet.g.alchemy.com/v2/YOUR_API_KEY"
BSC_RPC_URL="https://bnb-mainnet.g.alchemy.com/v2/YOUR_API_KEY"
POLYGON_RPC_URL="https://polygon-mainnet.g.alchemy.com/v2/YOUR_API_KEY"
SOLANA_RPC_URL="https://solana-mainnet.g.alchemy.com/v2/YOUR_API_KEY"
TRON_RPC_URL="https://api.trongrid.io"
TRON_PRO_API_KEY="your-trongrid-api-key"

# Optional: Adjust chunk size for paid/PAYG RPC plans (defaults to 10 for Alchemy Free Tier)
BLOCKCHAIN_EVM_LOG_CHUNK_SIZE=10

# Optional: Address Index Strategy ('auto', 'redis', 'database', 'array')
BLOCKCHAIN_ADDRESS_INDEX_STRATEGY=auto
```

#### Step 4: Update Your Scheduled Commands (`routes/console.php`)
1. **Switch to Block Ingestion**:
   ```php
   // Change from default per-wallet polling to block-level event ingestion:
   Schedule::command('blockchainsdk:monitor --strategy=block-ingest')
       ->everyMinute()
       ->withoutOverlapping();
   ```
2. **Switch to Token-First Sweeping**:
   ```php
   // Sweeps all configured ERC-20/SPL/TRC-20 tokens first before draining native gas coin:
   Schedule::command('blockchainsdk:sweep --all --sponsor --credit')
       ->everyFifteenMinutes()
       ->withoutOverlapping();
   ```

#### Step 5: (Optional) Access Exact Confirmed Fees
If you are programmatically recording sweep fees, update your calls to use the normalized `TransactionReceipt`:
```php
// Old (v1.0.x):
$fee = $result->feeSpent ?? 0;

// New (v1.1.0):
$exactFeeNative = $result->feeSpent();             // In native coin (ETH, BNB, MATIC, SOL, TRX)
$exactFeeWei    = $result->receipt?->feeWei();     // In atomic units (Wei, Sun, Satoshis)
$gasUsed        = $result->receipt?->gasUsed;      // Actual units of gas consumed on-chain
```

---

# Part 2: Standalone / Vanilla PHP Guide (Non-Laravel)

You can use `blockchain-sdk-php` in any PHP application, microservice, or framework (Symfony, Slim, Laminas, WordPress, pure PHP CLI scripts) without Laravel.

### 1. Initialization

Instantiate the `BlockchainManager` with a configuration array:

```php
require_once __DIR__ . '/vendor/autoload.php';

use BlockchainSdk\BlockchainManager;

$blockchain = new BlockchainManager([
    'default' => 'ethereum',
    'networks' => [
        'ethereum' => [
            'type'      => 'evm',
            'chain_id'  => 1,
            'rpc_nodes' => ['https://cloudflare-eth.com'],
            'tokens'    => [
                'USDT' => [
                    'name'     => 'Tether USD',
                    'contract' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
                    'decimals' => 6,
                    'status'   => 'enabled',
                ],
            ],
        ],
        'bsc' => [
            'type'      => 'evm',
            'chain_id'  => 56,
            'rpc_nodes' => ['https://bsc-dataseed.binance.org'],
            'tokens'    => [
                'USDT' => [
                    'name'     => 'Tether USD',
                    'contract' => '0x55d398326f99059fF775485246999027B3197955',
                    'decimals' => 18,
                    'status'   => 'enabled',
                ],
            ],
        ],
        'solana' => [
            'type'      => 'solana',
            'rpc_nodes' => ['https://api.mainnet-beta.solana.com'],
            'tokens'    => [
                'USDC' => [
                    'name'     => 'USD Coin',
                    'contract' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                    'decimals' => 6,
                    'status'   => 'enabled',
                ],
            ],
        ],
        'tron' => [
            'type'      => 'tron',
            'rpc_nodes' => ['https://api.trongrid.io'],
            'tokens'    => [
                'USDT' => [
                    'name'     => 'Tether USD',
                    'contract' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                    'decimals' => 6,
                    'status'   => 'enabled',
                ],
            ],
        ],
        'bitcoin' => [
            'type'      => 'bitcoin',
            'rpc_nodes' => ['https://mempool.space/api'],
            'tokens'    => [],
        ],
    ],
]);
```

---

### 2. Validating Addresses in Vanilla PHP

```php
// Validate EVM address
$isValidEth = $blockchain->driver('ethereum')->validateAddress('0xdAC17F958D2ee523a2206206994597C13D831ec7'); // true

// Validate Solana address
$isValidSol = $blockchain->driver('solana')->validateAddress('EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v'); // true

// Validate TRON address
$isValidTron = $blockchain->driver('tron')->validateAddress('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t'); // true

// Or using the manager shortcut
$isValidBtc = $blockchain->validateAddress('bitcoin', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4'); // true
```

---

### 3. Generating Wallets in Vanilla PHP

```php
// Generate a Solana keypair
$solKeypair = $blockchain->driver('solana')->generateWallet();
echo "Solana Address: " . $solKeypair->address . "\n";
echo "Solana Private Key: " . $solKeypair->privateKey . "\n";

// Generate an EVM keypair
$ethKeypair = $blockchain->driver('ethereum')->generateWallet();
echo "EVM Address: " . $ethKeypair->address . "\n";
echo "EVM Private Key: " . $ethKeypair->privateKey . "\n";

// Generate a Bitcoin Bech32 SegWit keypair
$btcKeypair = $blockchain->driver('bitcoin')->generateWallet();
echo "Bitcoin Address: " . $btcKeypair->address . "\n";

// Generate a TRON Base58Check keypair
$tronKeypair = $blockchain->driver('tron')->generateWallet();
echo "TRON Address: " . $tronKeypair->address . "\n";
```

---

### 4. Querying Balances & Checking Tokens in Vanilla PHP

```php
// 1. Query Native Balance
$nativeBalance = $blockchain->driver('ethereum')->getBalance('0xYourAddress...');
echo "ETH Balance: " . $nativeBalance->balanceFormatted . "\n";

// 2. Query Token Balance
$usdtBalance = $blockchain->driver('ethereum')->getBalance(
    address: '0xYourAddress...',
    tokenContract: '0xdAC17F958D2ee523a2206206994597C13D831ec7'
);
echo "USDT Balance: " . $usdtBalance->balanceFormatted . "\n";

// 3. Helper Token Lookups
$token = $blockchain->findToken('bsc', 'USDT');
if ($token && $blockchain->isTokenEnabled('bsc', 'USDT')) {
    echo "Found enabled token contract: " . $token['contract'] . "\n";
}
```

---

### 5. Sweeping Sub-Wallets & Sponsoring Gas in Vanilla PHP

```php
// Sweeping Native Currency
$sweepResult = $blockchain->driver('ethereum')->sweep(
    fromPrivateKey: $subWalletPrivateKey,
    toAddress: '0xMasterVaultAddress...'
);

if ($sweepResult->success) {
    echo "Sweep Successful! TxHash: " . $sweepResult->txHash . "\n";
} else {
    echo "Sweep Failed: " . $sweepResult->errorMessage . "\n";
}

// Sweeping Tokens with Gas Sponsorship
$sponsorResult = $blockchain->driver('bsc')->sweepTokenWithGasSponsorship(
    subWalletPrivateKey: $subWalletPrivateKey,
    masterGasPrivateKey: $masterGasPrivateKey,
    toVaultAddress:      '0xMasterVaultAddress...',
    tokenContract:       '0x55d398326f99059fF775485246999027B3197955' // USDT
);

if ($sponsorResult->success) {
    echo "Gas Sponsored and Swept! TxHash: " . $sponsorResult->txHash . "\n";
}
```

---

# Part 3: Architecture & Security

### Adding Custom Tokens
You can register new custom or standard ERC-20, SPL, or TRC-20 tokens on any blockchain by adding them to the `tokens` array:

```php
'SYMBOL' => [
    'name'     => 'Full Token Name',                  // Descriptive token name
    'contract' => '0xContractAddress...',            // Smart Contract or Mint Address
    'decimals' => 18,                                 // Decimal precision (e.g. 6 for USDT/USDC, 18 for standard)
    'status'   => 'enabled',                          // 'enabled' or 'disabled'
],
```

### Testing
Run the package test suite with PHPUnit:

```bash
# Standalone
composer test

# Within Laravel
php artisan test vendor/blockchain-sdk/blockchain-sdk-php/tests/BlockchainSdkTest.php
```

### Security Best Practices
1. **Keep Private Keys Encrypted at Rest**: Always store custodial keys using strong symmetric encryption (e.g. `AES-256-GCM` / Laravel's `Crypt::encryptString` or `casts => ['private_key' => 'encrypted']`).
2. **Separate Cold Master Vaults from Hot Gas Stations**: Keep your main reserves in cold storage (`master_wallets`), while maintaining small working balances in hot gas station wallets (`master_gas_wallets`) solely for gas fueling.
3. **Never Pass Private Keys over RPC**: All signing in this library happens locally on your application server. The RPC nodes only receive the final, cryptographically signed raw byte payload.
4. **Use Dedicated RPC Nodes in Production**: While public RPCs are supported, running dedicated nodes (e.g. QuickNode, Alchemy, Helius, Trongrid) provides higher throughput and zero rate-limiting.

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

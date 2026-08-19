# Blockchain SDK for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrokwor/blockchain-sdk-php.svg?style=flat-square)](https://packagist.org/packages/mrokwor/blockchain-sdk-php)
[![Total Downloads](https://img.shields.io/packagist/dt/mrokwor/blockchain-sdk-php.svg?style=flat-square)](https://packagist.org/packages/mrokwor/blockchain-sdk-php)
[![PHP Version Require](https://img.shields.io/packagist/dependency-v/mrokwor/blockchain-sdk-php/php?style=flat-square)](https://php.net)
[![License](https://img.shields.io/packagist/l/mrokwor/blockchain-sdk-php.svg?style=flat-square)](LICENSE.md)

A high-performance, **zero-third-party-cryptography** blockchain SDK for PHP 8.2+ and Laravel. Generate multi-chain custodial/deposit addresses, validate addresses with EIP-55/Base58/Bech32 checksums, sign transactions locally and offline, estimate network fees, query token balances, auto-fuel gas to deposit sub-wallets, track deposits with database models, and sweep customer funds into master cold vaults across **17+ supported blockchains** including **EVM (Ethereum, BNB Smart Chain, Polygon, Arbitrum, Base, Optimism, Avalanche, Fantom, Cronos, Linea, Scroll, zkSync, Celo, Mantle)**, **Solana**, **Bitcoin (Native SegWit Bech32)**, and **TRON**.

---

## Features

- **Zero External Cryptographic Dependencies**: Pure-PHP mathematical primitives for Secp256k1 (with GMP acceleration), EVM Keccak-256 permutation, RFC-6979 deterministic nonce generation, Base58/Base58Check, and Bech32.
- **17+ Supported Blockchains & Standard Tokens**: Pre-configured registry of supported token contracts, decimals, and enable/disable statuses for USDT, USDC, WBTC, DAI, LINK, UNI, and more.
- **Multi-Chain Address Validation & Laravel Rules**: Built-in address validation across all 17 chains (EVM 0x hex & EIP-55 checksums, Solana Base58 32-byte pubkeys, TRON Base58Check T-addresses with 0x41 prefix, Bitcoin Bech32/Base58) and ready-to-use Laravel `BlockchainAddress` validation rule.
- **Automated Gas Station & Master Vault Generator**: Generate master receiving vaults and hot gas station private keys in one command (`php artisan blockchainsdk:generate-master-wallets`) with automated AES-256 encryption and `.env` storage.
- **Automated Gas Station & Fee Sponsorship**: Solves the "empty gas tank" problem. Automatically detects if a customer sub-wallet has 0 native currency (0 BNB/TRX/ETH/SOL) and dispatches exact gas from a Master Hot Gas Wallet before executing the token sweep.
- **Publishable Migrations & Eloquent Models**: Publish customizable database schemas and models (`BlockchainSdkWallet`, `BlockchainSdkDeposit`, `BlockchainSdkSweep`) directly into `app/Models/`.
- **Event-Driven Value Crediting**: Dispatches lifecycle events (`DepositConfirmed`, `WalletSwept`) to give balance value to users immediately upon deposit confirmation or after vault consolidation.
- **Multi-Node Failover RPC Client**: Built-in round-robin health-checking and automatic failover across multiple fallback RPC endpoints.
- **Automated Vault Sweeper**: Compute network fees and automatically sweep funds from customer deposit addresses into master cold vaults.
- **Full Laravel 10/11/12 Support**: First-class Service Provider, Facade (`Blockchain::driver(...)`), publishable `config/blockchainsdk.php`, and ready-to-use Artisan commands (`blockchainsdk:generate-master-wallets`, `blockchainsdk:sweep`, `blockchainsdk:monitor`).
- **Offline & Secure Signing**: Private keys never leave your application server; transactions are constructed and signed locally before raw broadcast.

---

## Supported Networks & Driver Identifiers

The SDK supports **17 individual network drivers** out of the box:

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

# Part 1: Laravel Integration Guide

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

To sweep customer deposits into a central vault or sponsor network fees for dry sub-wallets, your platform needs Master Receiving Addresses and Hot Gas Station keys. 

You can generate all master vault credentials in a single command with **automatic AES-256 encryption** and **direct `.env` storage**:

```bash
# Generate for all 17 supported blockchains (Encrypted & Saved to .env)
php artisan blockchainsdk:generate-master-wallets

# Generate for a specific blockchain network (e.g. BSC, Polygon, Solana, TRON)
php artisan blockchainsdk:generate-master-wallets bsc
php artisan blockchainsdk:generate-master-wallets solana
php artisan blockchainsdk:generate-master-wallets tron

# Overwrite existing keys in .env
php artisan blockchainsdk:generate-master-wallets --force

# Console output only (do not write to .env)
php artisan blockchainsdk:generate-master-wallets --no-store

# Store raw plaintext private keys (disable encryption)
php artisan blockchainsdk:generate-master-wallets --no-encrypt
```

#### How Master Keys Are Stored in `.env`
```env
# Generated Master Receiving Vaults
BLOCKCHAIN_MASTER_ETHEREUM="0x71C8360f3a104d31a4570b9A821929342939b422"
BLOCKCHAIN_MASTER_BSC="0x55d398326f99059fF775485246999027B3197955"
BLOCKCHAIN_MASTER_SOLANA="EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v"
BLOCKCHAIN_MASTER_TRON="TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t"
BLOCKCHAIN_MASTER_BITCOIN="bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4"

# Generated Hot Gas Station Keys (Encrypted with Laravel's APP_KEY)
BLOCKCHAIN_GAS_KEY_ETHEREUM="eyJpdiI6Inl1Vn...[ENCRYPTED_SECRET]..."
BLOCKCHAIN_GAS_KEY_BSC="eyJpdiI6Inl1Vn...[ENCRYPTED_SECRET]..."
BLOCKCHAIN_GAS_KEY_SOLANA="eyJpdiI6Inl1Vn...[ENCRYPTED_SECRET]..."
BLOCKCHAIN_GAS_KEY_TRON="eyJpdiI6Inl1Vn...[ENCRYPTED_SECRET]..."
```

> [!TIP]
> **Transparent Auto-Decryption**: The SDK automatically detects whether a gas key in `.env` or `config/blockchainsdk.php` is encrypted (`eyJpdiI...`) or plain hex. It decrypts it seamlessly on-the-fly during sweeps and fee sponsorships without any manual decryption calls.

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

---

### 7. Giving Value to Users After Sweeping or Depositing in Laravel

In crypto platforms and fintech applications, **"giving value"** means crediting the customer's account balance, ledger, or wallet in your application database. The SDK dispatches events so you can execute your own custom credit logic.

#### Paradigm A: Credit After Master Vault Sweep (Post-Sweep Hook)

1. Execute the sweep command with the `--credit` flag:
   ```bash
   php artisan blockchainsdk:sweep bsc --token=USDT --sponsor --credit
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
| `php artisan blockchainsdk:generate-master-wallets {network?}` | Generates master receiving vaults and hot gas keys with AES-256 encryption and `.env` storage | `--no-encrypt`, `--no-store`, `--force` |
| `php artisan blockchainsdk:sweep {network?}` | Sweeps sub-wallets into central cold vault | `--token=` (symbol/contract), `--sponsor` (auto-gas), `--credit` (dispatch value event) |
| `php artisan blockchainsdk:monitor` | Multi-chain background deposit confirmation listener | `--network=` (filter network), `--once` (run single pass) |
| `php artisan vendor:publish --tag="blockchainsdk-config"` | Publishes `config/blockchainsdk.php` | `--force` (overwrite existing) |
| `php artisan vendor:publish --tag="blockchainsdk-migrations"` | Publishes database migrations | `--force` (overwrite existing) |
| `php artisan vendor:publish --tag="blockchainsdk-models"` | Publishes Eloquent models to `app/Models/` | `--force` (overwrite existing) |

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
2. **Separate Cold Master Vaults from Hot Gas Stations**: Keep your main reserves in cold storage (`master_wallets`), while maintaining small working balances in hot gas station wallets (`master_gas_keys`) solely for gas fueling.
3. **Never Pass Private Keys over RPC**: All signing in this library happens locally on your application server. The RPC nodes only receive the final, cryptographically signed raw byte payload.
4. **Use Dedicated RPC Nodes in Production**: While public RPCs are supported, running dedicated nodes (e.g. QuickNode, Alchemy, Helius, Trongrid) provides higher throughput and zero rate-limiting.

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.